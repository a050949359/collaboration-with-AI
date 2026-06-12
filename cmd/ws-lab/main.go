package main

import (
	"context"
	"encoding/json"
	"flag"
	"log"
	"math"
	"math/rand/v2"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/coder/websocket"
	"github.com/coder/websocket/wsjson"
	"github.com/redis/go-redis/v9"
)

// ── Config ────────────────────────────────────────────────────────────────────

var (
	wsAddr         = flag.String("ws-addr", "127.0.0.1:9001", "WebSocket listen address")
	mgmtAddr       = flag.String("mgmt-addr", "127.0.0.1:9002", "Management HTTP listen address")
	pidFile        = flag.String("pid-file", "", "Path to write PID file")
	logFile        = flag.String("log-file", "", "Path to log file (appended); empty = stderr")
	redisAddr      = flag.String("redis-addr", "127.0.0.1:6379", "Redis address")
	redisPass      = flag.String("redis-password", "", "Redis password")
	allowedOrigins = flag.String("allowed-origins", "localhost:*", "Comma-separated WebSocket origin patterns")
)

var rdb *redis.Client

var globalDoShutdown func()

// serverActivityCh lets any room signal activity to keep the server alive.
var serverActivityCh = make(chan struct{}, 64)

const heartbeatTimeout = 30 * time.Second

// ── Data ──────────────────────────────────────────────────────────────────────

type DataPoint struct {
	Type  string  `json:"type"`
	Ts    int64   `json:"ts"`
	Value float64 `json:"value"`
}

type series struct {
	value float64
}

func (s *series) next() DataPoint {
	s.value = clamp(s.value+rand.Float64()*10-5, 0, 100)
	return DataPoint{
		Type:  "data",
		Ts:    time.Now().UnixMilli(),
		Value: math.Round(s.value*10) / 10,
	}
}

func clamp(v, min, max float64) float64 {
	if v < min {
		return min
	}
	if v > max {
		return max
	}
	return v
}

// ── Room & RoomManager ────────────────────────────────────────────────────────

type RoomType string

const (
	RoomTypeGlobal RoomType = "global"
	RoomTypeGacha  RoomType = "gacha"
)

type incomingMsg struct {
	c    *client
	data map[string]string
}

type authResult struct {
	c    *client
	ok   bool
	name string
}

type RoomInfo struct {
	ID      string   `json:"id"`
	Type    RoomType `json:"type"`
	Clients int      `json:"clients"`
}

type Room struct {
	id           string
	roomType     RoomType
	hostName     string
	host         *client
	machineState map[string]string // last machine_state from host; nil until set
	connsByIP    map[string]int    // concurrent WS connections per remote IP
	clients      map[*client]struct{}
	join         chan *client
	leave        chan *client
	incoming     chan incomingMsg
	broadcastExt chan []byte
	authDone     chan authResult
	shutdown     chan struct{}
	clientCount  atomic.Int64
	streaming    atomic.Bool
	lastActivity atomic.Int64 // Unix nano
}

func newRoom(id string, roomType RoomType, hostName string) *Room {
	r := &Room{
		id:           id,
		roomType:     roomType,
		hostName:     hostName,
		connsByIP:    make(map[string]int),
		clients:      make(map[*client]struct{}),
		join:         make(chan *client, 8),
		leave:        make(chan *client, 8),
		incoming:     make(chan incomingMsg, 64),
		broadcastExt: make(chan []byte, 8),
		authDone:     make(chan authResult, 16),
		shutdown:     make(chan struct{}),
	}
	r.lastActivity.Store(time.Now().UnixNano())
	return r
}

func (r *Room) touch() { r.lastActivity.Store(time.Now().UnixNano()) }

func (r *Room) shouldShutdown() bool {
	last := time.Unix(0, r.lastActivity.Load())
	if time.Since(last) <= heartbeatTimeout {
		return false
	}
	// streaming 中但所有 client 均已離線超過 heartbeatTimeout → 允許 shutdown
	return !r.streaming.Load() || r.clientCount.Load() <= 0
}

func (r *Room) broadcastBytes(msg []byte) {
	for c := range r.clients {
		select {
		case c.send <- msg:
		default:
		}
	}
}

func (r *Room) evictStale() {
	now := time.Now()
	for c := range r.clients {
		last := time.Unix(0, c.lastPing.Load())
		if now.Sub(last) > heartbeatTimeout {
			log.Printf("evicting stale client (no ping for %s)", heartbeatTimeout)
			c.conn.Close(websocket.StatusPolicyViolation, "heartbeat timeout")
			delete(r.clients, c)
			r.clientCount.Add(-1)
		}
	}
}

type findReq struct {
	id   string
	resp chan *Room
}

type listReq struct {
	resp chan []RoomInfo
}

type RoomManager struct {
	rooms        map[string]*Room
	find         chan findReq
	add          chan *Room
	remove       chan string
	list         chan listReq
	broadcastAll chan []byte
}

func newRoomManager() *RoomManager {
	return &RoomManager{
		rooms:        make(map[string]*Room),
		find:         make(chan findReq),
		add:          make(chan *Room),
		remove:       make(chan string),
		list:         make(chan listReq),
		broadcastAll: make(chan []byte),
	}
}

func (m *RoomManager) run() {
	for {
		select {
		case req := <-m.find:
			req.resp <- m.rooms[req.id]
		case r := <-m.add:
			m.rooms[r.id] = r
			go r.run(m)
		case id := <-m.remove:
			if r, ok := m.rooms[id]; ok {
				close(r.shutdown)
				delete(m.rooms, id)
			}
		case req := <-m.list:
			result := make([]RoomInfo, 0, len(m.rooms))
			for _, r := range m.rooms {
				result = append(result, RoomInfo{
					ID:      r.id,
					Type:    r.roomType,
					Clients: int(r.clientCount.Load()),
				})
			}
			req.resp <- result
		case msg := <-m.broadcastAll:
			for _, r := range m.rooms {
				select {
				case r.broadcastExt <- msg:
				default:
				}
			}
		}
	}
}

func (r *Room) run(manager *RoomManager) {
	switch r.roomType {
	case RoomTypeGlobal:
		r.runGlobal()
	case RoomTypeGacha:
		r.runGacha(manager)
	}
}

func (r *Room) runGacha(manager *RoomManager) {
	hbCheck := time.NewTicker(10 * time.Second)
	defer hbCheck.Stop()

	for {
		select {
		case <-r.shutdown:
			return
		case c := <-r.join:
			log.Printf("gacha join: remoteIP=%q connsByIP=%v", c.remoteIP, r.connsByIP)
			if r.connsByIP[c.remoteIP] >= 3 {
				c.conn.Close(websocket.StatusPolicyViolation, "too many connections from your IP")
				continue
			}
			r.connsByIP[c.remoteIP]++
			r.clients[c] = struct{}{}
			r.clientCount.Add(1)
			r.touch()
			welcome, _ := json.Marshal(map[string]string{"type": "welcome"})
			select {
			case c.send <- welcome:
			default:
			}
			if r.machineState != nil {
				if out, err := json.Marshal(r.machineState); err == nil {
					select {
					case c.send <- out:
					default:
					}
				}
			}
		case c := <-r.leave:
			if _, ok := r.clients[c]; !ok {
				continue // was rejected on join, not tracked
			}
			delete(r.clients, c)
			r.clientCount.Add(-1)
			r.connsByIP[c.remoteIP]--
			if r.connsByIP[c.remoteIP] <= 0 {
				delete(r.connsByIP, c.remoteIP)
			}
			if r.host != nil && c == r.host {
				out, _ := json.Marshal(map[string]string{"type": "room_closed"})
				r.broadcastBytes(out)
				for remaining := range r.clients {
					close(remaining.send)
				}
				manager.Remove(r.id)
				return
			}
			if c.userName != "" {
				out, _ := json.Marshal(map[string]string{"type": "player_left", "name": c.userName})
				r.broadcastBytes(out)
			}
		case msg := <-r.incoming:
			r.handleGacha(msg)
		case res := <-r.authDone:
			if res.name != "" && res.c.userName == "" {
				res.c.userName = res.name
			}
			if !res.c.nameAnnounced && res.c.userName != "" {
				res.c.nameAnnounced = true
				out, _ := json.Marshal(map[string]string{"type": "player_joined", "name": res.c.userName})
				r.broadcastBytes(out)
			}
			if res.ok {
				res.c.isAuthed.Store(true)
				if r.hostName != "" && res.name == r.hostName && r.host == nil {
					res.c.isHost = true
					r.host = res.c
					log.Printf("gacha room %s: host connected (%s)", r.id, res.c.userName)
				}
			}
		case msg := <-r.broadcastExt:
			r.broadcastBytes(msg)
		case <-hbCheck.C:
			r.evictStale()
			if r.clientCount.Load() == 0 && r.shouldShutdown() {
				log.Printf("gacha room %s empty, removing", r.id)
				manager.Remove(r.id)
				return
			}
		}
	}
}

func (r *Room) handleGacha(msg incomingMsg) {
	c := msg.c
	r.touch()
	switch msg.data["type"] {
	case "auth":
		token := msg.data["token"]
		go func() {
			ok, name := verifyToken(token)
			r.authDone <- authResult{c: c, ok: ok, name: name}
		}()
	case "name":
		if name := msg.data["name"]; name != "" {
			r.authDone <- authResult{c: c, ok: false, name: name}
		}
	case "machine_state":
		if !c.isHost {
			return
		}
		r.machineState = msg.data
		out, _ := json.Marshal(msg.data)
		r.broadcastBytes(out)
	}
}

func (r *Room) runGlobal() {
	s := &series{value: 50}
	ticker := time.NewTicker(500 * time.Millisecond)
	hbCheck := time.NewTicker(10 * time.Second)
	defer ticker.Stop()
	defer hbCheck.Stop()

	for {
		select {
		case <-r.shutdown:
			return
		case c := <-r.join:
			r.clients[c] = struct{}{}
			r.clientCount.Add(1)
			r.touch()
		case c := <-r.leave:
			delete(r.clients, c)
			r.clientCount.Add(-1)
		case msg := <-r.incoming:
			r.handleGlobal(msg)
		case res := <-r.authDone:
			if res.ok {
				res.c.isAuthed.Store(true)
				res.c.userName = res.name
				log.Printf("client authed: %s", res.name)
			}
		case <-ticker.C:
			if r.shouldShutdown() {
				log.Println("global room idle, shutting down")
				if globalDoShutdown != nil {
					globalDoShutdown()
				}
				return
			}
			if r.streaming.Load() && r.clientCount.Load() > 0 {
				out, _ := json.Marshal(s.next())
				r.broadcastBytes(out)
			}
		case msg := <-r.broadcastExt:
			r.broadcastBytes(msg)
		case <-serverActivityCh:
			r.touch()
		case <-hbCheck.C:
			r.evictStale()
		}
	}
}

func (r *Room) handleGlobal(msg incomingMsg) {
	c := msg.c
	r.touch()
	switch msg.data["type"] {
	case "auth":
		token := msg.data["token"]
		go func() {
			ok, name := verifyToken(token)
			r.authDone <- authResult{c: c, ok: ok, name: name}
		}()
	case "command":
		if !c.isAuthed.Load() {
			return
		}
		if text := msg.data["text"]; text != "" {
			out, _ := json.Marshal(map[string]string{
				"type": "command",
				"text": text,
				"user": c.userName,
				"ts":   strconv.FormatInt(time.Now().UnixMilli(), 10),
			})
			r.broadcastBytes(out)
		}
	}
}

func (m *RoomManager) Get(id string) *Room {
	resp := make(chan *Room, 1)
	m.find <- findReq{id: id, resp: resp}
	return <-resp
}

func (m *RoomManager) Add(r *Room) { m.add <- r }
func (m *RoomManager) Remove(id string) { m.remove <- id }
func (m *RoomManager) BroadcastAll(msg []byte) { m.broadcastAll <- msg }

func (m *RoomManager) List() []RoomInfo {
	resp := make(chan []RoomInfo, 1)
	m.list <- listReq{resp: resp}
	return <-resp
}


// ── Client ────────────────────────────────────────────────────────────────────

type client struct {
	conn          *websocket.Conn
	lastPing      atomic.Int64 // unix nano
	send          chan []byte
	isAuthed      atomic.Bool
	isHost        bool
	userID        int
	userName      string
	nameAnnounced bool
	remoteIP      string
}

// ── Auth ──────────────────────────────────────────────────────────────────────

func verifyToken(token string) (bool, string) {
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	key := "ws-lab-auth:" + token
	name, err := rdb.GetDel(ctx, key).Result()
	if err != nil {
		return false, ""
	}
	return true, name
}

// ── WebSocket handler ─────────────────────────────────────────────────────────

func realIP(r *http.Request) string {
	if ip := r.Header.Get("CF-Connecting-IP"); ip != "" {
		return ip
	}
	if ip := r.Header.Get("X-Real-IP"); ip != "" {
		return ip
	}
	if ip := r.Header.Get("X-Forwarded-For"); ip != "" {
		return strings.SplitN(ip, ",", 2)[0]
	}
	host, _, _ := net.SplitHostPort(r.RemoteAddr)
	return host
}

const (
	rateMsgLimit  = 20
	rateMsgWindow = 10 * time.Second
)

func serveWS(manager *RoomManager, w http.ResponseWriter, r *http.Request) {
	path := strings.Trim(strings.TrimPrefix(r.URL.Path, "/ws-lab"), "/")
	roomID := "global"
	if path != "" {
		parts := strings.Split(path, "/")
		roomID = parts[len(parts)-1]
	}

	room := manager.Get(roomID)
	if room == nil {
		http.Error(w, "room not found", http.StatusNotFound)
		return
	}

	conn, err := websocket.Accept(w, r, &websocket.AcceptOptions{
		OriginPatterns: strings.Split(*allowedOrigins, ","),
	})
	if err != nil {
		log.Printf("accept: %v", err)
		return
	}

	c := &client{
		conn:     conn,
		send:     make(chan []byte, 32),
		remoteIP: realIP(r),
	}
	c.lastPing.Store(time.Now().UnixNano())

	room.join <- c
	defer func() { room.leave <- c }()

	ctx, cancel := context.WithCancel(r.Context())
	defer cancel()

	go func() {
		for {
			select {
			case msg, ok := <-c.send:
				if !ok {
					return
				}
				if err := conn.Write(ctx, websocket.MessageText, msg); err != nil {
					cancel()
					return
				}
			case <-ctx.Done():
				return
			}
		}
	}()

	pong, _ := json.Marshal(map[string]string{"type": "pong"})
	var (
		rateWindow = time.Now()
		rateCount  = 0
	)
	for {
		var msg map[string]string
		if err := wsjson.Read(ctx, conn, &msg); err != nil {
			break
		}
		if msg["type"] != "ping" {
			now := time.Now()
			if now.Sub(rateWindow) > rateMsgWindow {
				rateWindow = now
				rateCount = 0
			}
			rateCount++
			if rateCount > rateMsgLimit {
				conn.Close(websocket.StatusPolicyViolation, "rate limit exceeded")
				break
			}
		}
		switch msg["type"] {
		case "ping":
			c.lastPing.Store(time.Now().UnixNano())
			select {
			case c.send <- pong:
			default:
			}
			select {
			case serverActivityCh <- struct{}{}:
			default:
			}
		case "auth", "name":
			room.incoming <- incomingMsg{c: c, data: msg}
		default:
			if c.isAuthed.Load() {
				room.incoming <- incomingMsg{c: c, data: msg}
			}
		}
	}
}

// ── Main ──────────────────────────────────────────────────────────────────────

func main() {
	flag.Parse()

	if *logFile != "" {
		f, err := os.OpenFile(*logFile, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
		if err != nil {
			log.Fatalf("cannot open log file: %v", err)
		}
		defer f.Close()
		log.SetOutput(f)
	}

	rdb = redis.NewClient(&redis.Options{
		Addr:     *redisAddr,
		Password: *redisPass,
	})

	if *pidFile != "" {
		if err := os.WriteFile(*pidFile, []byte(strconv.Itoa(os.Getpid())), 0644); err != nil {
			log.Printf("warn: could not write pid file: %v", err)
		}
		defer os.Remove(*pidFile)
	}

	shutdown := make(chan struct{}, 1)
	closeOnce := sync.Once{}
	doShutdown := func() { closeOnce.Do(func() { close(shutdown) }) }
	globalDoShutdown = doShutdown

	sigs := make(chan os.Signal, 1)
	signal.Notify(sigs, syscall.SIGTERM, syscall.SIGINT)
	go func() {
		<-sigs
		log.Println("signal received, shutting down")
		doShutdown()
	}()

	manager := newRoomManager()
	go manager.run()

	globalRoom := newRoom("global", RoomTypeGlobal, "")
	manager.Add(globalRoom)

	// management server
	mgmt := &http.Server{Addr: *mgmtAddr}
	http.DefaultServeMux.HandleFunc("/shutdown", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}
		w.Write([]byte(`{"ok":true}`))
		doShutdown()
	})
	http.DefaultServeMux.HandleFunc("/stream/start", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}
		globalRoom.streaming.Store(true)
		globalRoom.touch()
		w.Write([]byte(`{"ok":true}`))
	})
	http.DefaultServeMux.HandleFunc("/stream/stop", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}
		globalRoom.streaming.Store(false)
		w.Write([]byte(`{"ok":true}`))
	})

	// Room management
	http.DefaultServeMux.HandleFunc("/rooms", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		switch r.Method {
		case http.MethodGet:
			json.NewEncoder(w).Encode(manager.List())
		case http.MethodPost:
			var body struct {
				ID       string   `json:"id"`
				Type     RoomType `json:"type"`
				HostName string   `json:"host_name"`
			}
			if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.ID == "" {
				http.Error(w, `{"error":"invalid body"}`, http.StatusBadRequest)
				return
			}
			if manager.Get(body.ID) == nil {
				manager.Add(newRoom(body.ID, body.Type, body.HostName))
			}
			w.Write([]byte(`{"ok":true}`))
		default:
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		}
	})
	http.DefaultServeMux.HandleFunc("/rooms/", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		path := strings.TrimPrefix(r.URL.Path, "/rooms/")

		// POST /rooms/{id}/broadcast
		if strings.HasSuffix(path, "/broadcast") {
			if r.Method != http.MethodPost {
				http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
				return
			}
			id := strings.TrimSuffix(path, "/broadcast")
			room := manager.Get(id)
			if room == nil {
				http.Error(w, `{"error":"room not found"}`, http.StatusNotFound)
				return
			}
			var body json.RawMessage
			if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
				http.Error(w, `{"error":"invalid json"}`, http.StatusBadRequest)
				return
			}
			select {
			case room.broadcastExt <- []byte(body):
				w.Write([]byte(`{"ok":true}`))
			default:
				http.Error(w, `{"error":"broadcast buffer full"}`, http.StatusServiceUnavailable)
			}
			return
		}

		// DELETE /rooms/{id}
		if r.Method != http.MethodDelete {
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}
		id := path
		if id == "" || id == "global" {
			http.Error(w, `{"error":"cannot delete this room"}`, http.StatusBadRequest)
			return
		}
		manager.Remove(id)
		w.Write([]byte(`{"ok":true}`))
	})

	go func() {
		log.Printf("mgmt listening on %s", *mgmtAddr)
		mgmt.ListenAndServe()
	}()

	// WebSocket server
	wsMux := http.NewServeMux()
	wsMux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		serveWS(manager, w, r)
	})
	ws := &http.Server{Addr: *wsAddr, Handler: wsMux}
	go func() {
		log.Printf("ws listening on %s", *wsAddr)
		if err := ws.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("ws: %v", err)
		}
	}()

	<-shutdown
	log.Println("shutdown requested")
	out, _ := json.Marshal(map[string]string{"type": "server_shutdown"})
	manager.BroadcastAll(out)
	shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 500*time.Millisecond)
	defer shutdownCancel()
	ws.Shutdown(shutdownCtx)
	mgmt.Close()
}

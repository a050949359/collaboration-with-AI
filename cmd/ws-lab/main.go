package main

import (
	"context"
	"encoding/json"
	"flag"
	"log"
	"math"
	"math/rand/v2"
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
	wsAddr      = flag.String("ws-addr", "127.0.0.1:9001", "WebSocket listen address")
	mgmtAddr    = flag.String("mgmt-addr", "127.0.0.1:9002", "Management HTTP listen address")
	pidFile     = flag.String("pid-file", "", "Path to write PID file")
	redisAddr = flag.String("redis-addr", "127.0.0.1:6379", "Redis address")
	redisPass = flag.String("redis-password", "", "Redis password")
)

var rdb *redis.Client

var globalDoShutdown func()

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

type RoomInfo struct {
	ID      string   `json:"id"`
	Type    RoomType `json:"type"`
	Clients int      `json:"clients"`
}

type Room struct {
	id           string
	roomType     RoomType
	clients      map[*client]struct{}
	join         chan *client
	leave        chan *client
	incoming     chan incomingMsg
	shutdown     chan struct{}
	clientCount  atomic.Int64
	streaming    atomic.Bool
	lastActivity atomic.Int64 // Unix nano
}

func newRoom(id string, roomType RoomType) *Room {
	r := &Room{
		id:       id,
		roomType: roomType,
		clients:  make(map[*client]struct{}),
		join:     make(chan *client, 8),
		leave:    make(chan *client, 8),
		incoming: make(chan incomingMsg, 64),
		shutdown: make(chan struct{}),
	}
	r.lastActivity.Store(time.Now().UnixNano())
	return r
}

func (r *Room) touch() { r.lastActivity.Store(time.Now().UnixNano()) }

func (r *Room) shouldShutdown() bool {
	if r.streaming.Load() {
		return false
	}
	last := time.Unix(0, r.lastActivity.Load())
	return time.Since(last) > heartbeatTimeout
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
		if now.Sub(c.lastPing) > heartbeatTimeout {
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
	rooms  map[string]*Room
	find   chan findReq
	add    chan *Room
	remove chan string
	list   chan listReq
}

func newRoomManager() *RoomManager {
	return &RoomManager{
		rooms:  make(map[string]*Room),
		find:   make(chan findReq),
		add:    make(chan *Room),
		remove: make(chan string),
		list:   make(chan listReq),
	}
}

func (m *RoomManager) run() {
	for {
		select {
		case req := <-m.find:
			req.resp <- m.rooms[req.id]
		case r := <-m.add:
			m.rooms[r.id] = r
			go r.run()
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
		}
	}
}

func (r *Room) run() {
	switch r.roomType {
	case RoomTypeGlobal:
		r.runGlobal()
	case RoomTypeGacha:
		// Step 6
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
		case <-hbCheck.C:
			r.evictStale()
		}
	}
}

func (r *Room) handleGlobal(msg incomingMsg) {
	c := msg.c
	r.touch()
	switch msg.data["type"] {
	case "ping":
		c.lastPing = time.Now()
		pong, _ := json.Marshal(map[string]string{"type": "pong"})
		select {
		case c.send <- pong:
		default:
		}
	case "auth":
		if ok, name := verifyToken(msg.data["token"]); ok {
			c.isAuthed = true
			c.userName = name
			log.Printf("client authed: %s", name)
		}
	case "command":
		if !c.isAuthed {
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

func (m *RoomManager) List() []RoomInfo {
	resp := make(chan []RoomInfo, 1)
	m.list <- listReq{resp: resp}
	return <-resp
}

// ── Client ────────────────────────────────────────────────────────────────────

type client struct {
	conn     *websocket.Conn
	lastPing time.Time
	send     chan []byte
	isAuthed bool
	userID   int
	userName string
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

func serveWS(manager *RoomManager, w http.ResponseWriter, r *http.Request) {
	room := manager.Get("global") // Step 4: parse room from URL
	if room == nil {
		http.Error(w, "room not found", http.StatusNotFound)
		return
	}

	conn, err := websocket.Accept(w, r, &websocket.AcceptOptions{
		InsecureSkipVerify: true,
	})
	if err != nil {
		log.Printf("accept: %v", err)
		return
	}

	c := &client{
		conn:     conn,
		lastPing: time.Now(),
		send:     make(chan []byte, 32),
	}

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

	for {
		var msg map[string]string
		if err := wsjson.Read(ctx, conn, &msg); err != nil {
			break
		}
		switch msg["type"] {
		case "ping", "auth":
			room.incoming <- incomingMsg{c: c, data: msg}
		default:
			if c.isAuthed {
				room.incoming <- incomingMsg{c: c, data: msg}
			}
		}
	}
}

// ── Main ──────────────────────────────────────────────────────────────────────

func main() {
	flag.Parse()

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

	globalRoom := newRoom("global", RoomTypeGlobal)
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
				ID   string   `json:"id"`
				Type RoomType `json:"type"`
			}
			if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.ID == "" {
				http.Error(w, `{"error":"invalid body"}`, http.StatusBadRequest)
				return
			}
			manager.Add(newRoom(body.ID, body.Type))
			w.Write([]byte(`{"ok":true}`))
		default:
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		}
	})
	http.DefaultServeMux.HandleFunc("/rooms/", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodDelete {
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}
		id := strings.TrimPrefix(r.URL.Path, "/rooms/")
		if id == "" || id == "global" {
			http.Error(w, `{"error":"cannot delete this room"}`, http.StatusBadRequest)
			return
		}
		manager.Remove(id)
		w.Header().Set("Content-Type", "application/json")
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
	ws.Close()
	mgmt.Close()
}

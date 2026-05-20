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
	id          string
	roomType    RoomType
	clients     map[*client]struct{}
	join        chan *client
	leave       chan *client
	incoming    chan incomingMsg
	shutdown    chan struct{}
	clientCount atomic.Int64
}

func newRoom(id string, roomType RoomType) *Room {
	return &Room{
		id:       id,
		roomType: roomType,
		clients:  make(map[*client]struct{}),
		join:     make(chan *client, 8),
		leave:    make(chan *client, 8),
		incoming: make(chan incomingMsg, 64),
		shutdown: make(chan struct{}),
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
	for {
		select {
		case <-r.shutdown:
			return
		case c := <-r.join:
			r.clients[c] = struct{}{}
			r.clientCount.Add(1)
		case c := <-r.leave:
			delete(r.clients, c)
			r.clientCount.Add(-1)
		case <-r.incoming:
			// Step 2 填入
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

// ── Hub ───────────────────────────────────────────────────────────────────────

type client struct {
	conn     *websocket.Conn
	lastPing time.Time
	send     chan []byte
	isAuthed bool
	userID   int
	userName string
}

type hub struct {
	mu           sync.Mutex
	clients      map[*client]struct{}
	lastActivity time.Time
	streaming    bool
}

func newHub() *hub {
	return &hub{clients: make(map[*client]struct{}), lastActivity: time.Now()}
}

func (h *hub) touch() {
	h.mu.Lock()
	h.lastActivity = time.Now()
	h.mu.Unlock()
}

func (h *hub) setStreaming(v bool) {
	h.mu.Lock()
	h.streaming = v
	h.lastActivity = time.Now()
	h.mu.Unlock()
}

func (h *hub) isStreaming() bool {
	h.mu.Lock()
	defer h.mu.Unlock()
	return h.streaming
}

// shouldShutdown returns true only when not streaming and idle timeout exceeded.
func (h *hub) shouldShutdown() bool {
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.streaming {
		return false
	}
	return time.Since(h.lastActivity) > heartbeatTimeout
}

func (h *hub) add(c *client) {
	h.mu.Lock()
	h.clients[c] = struct{}{}
	h.lastActivity = time.Now()
	h.mu.Unlock()
}

func (h *hub) remove(c *client) {
	h.mu.Lock()
	delete(h.clients, c)
	h.mu.Unlock()
}

func (h *hub) count() int {
	h.mu.Lock()
	defer h.mu.Unlock()
	return len(h.clients)
}

func (h *hub) broadcast(msg []byte) {
	h.mu.Lock()
	defer h.mu.Unlock()
	for c := range h.clients {
		select {
		case c.send <- msg:
		default:
		}
	}
}

func (h *hub) evictStale() {
	h.mu.Lock()
	defer h.mu.Unlock()
	now := time.Now()
	for c := range h.clients {
		if now.Sub(c.lastPing) > heartbeatTimeout {
			log.Printf("evicting stale client (no ping for %s)", heartbeatTimeout)
			c.conn.Close(websocket.StatusPolicyViolation, "heartbeat timeout")
			delete(h.clients, c)
		}
	}
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

func (h *hub) serveWS(w http.ResponseWriter, r *http.Request) {
	conn, err := websocket.Accept(w, r, &websocket.AcceptOptions{
		InsecureSkipVerify: true, // origin check handled by nginx
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
	h.add(c)
	defer h.remove(c)

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
		case "ping":
			h.mu.Lock()
			c.lastPing = time.Now()
			h.mu.Unlock()
			h.touch()
			pong, _ := json.Marshal(map[string]string{"type": "pong"})
			select {
			case c.send <- pong:
			default:
			}
		case "auth":
			if ok, name := verifyToken(msg["token"]); ok {
				h.mu.Lock()
				c.isAuthed = true
				c.userName = name
				h.mu.Unlock()
				log.Printf("client authed: %s", name)
			}
		case "command":
			h.mu.Lock()
			authed := c.isAuthed
			name := c.userName
			h.mu.Unlock()
			if !authed {
				break
			}
			if text := msg["text"]; text != "" {
				out, _ := json.Marshal(map[string]string{
					"type": "command",
					"text": text,
					"user": name,
					"ts":   strconv.FormatInt(time.Now().UnixMilli(), 10),
				})
				h.broadcast(out)
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

	h := newHub()
	s := &series{value: 50}

	shutdown := make(chan struct{}, 1)
	closeOnce := sync.Once{}
	doShutdown := func() { closeOnce.Do(func() { close(shutdown) }) }

	sigs := make(chan os.Signal, 1)
	signal.Notify(sigs, syscall.SIGTERM, syscall.SIGINT)
	go func() {
		<-sigs
		log.Println("signal received, shutting down")
		doShutdown()
	}()

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
		h.setStreaming(true)
		w.Write([]byte(`{"ok":true}`))
	})
	http.DefaultServeMux.HandleFunc("/stream/stop", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}
		h.setStreaming(false)
		w.Write([]byte(`{"ok":true}`))
	})
	go func() {
		log.Printf("mgmt listening on %s", *mgmtAddr)
		mgmt.ListenAndServe()
	}()

	// WebSocket server
	wsMux := http.NewServeMux()
	wsMux.HandleFunc("/", h.serveWS)
	ws := &http.Server{Addr: *wsAddr, Handler: wsMux}
	go func() {
		log.Printf("ws listening on %s", *wsAddr)
		if err := ws.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("ws: %v", err)
		}
	}()

	ticker := time.NewTicker(500 * time.Millisecond)
	defer ticker.Stop()

	hbCheck := time.NewTicker(10 * time.Second)
	defer hbCheck.Stop()

	for {
		select {
		case <-shutdown:
			log.Println("shutdown requested")
			ws.Close()
			mgmt.Close()
			return

		case <-ticker.C:
			if h.shouldShutdown() {
				log.Println("idle for 30s, shutting down")
				ws.Close()
				mgmt.Close()
				return
			}
			if h.isStreaming() && h.count() > 0 {
				msg, _ := json.Marshal(s.next())
				h.broadcast(msg)
			}

		case <-hbCheck.C:
			h.evictStale()
		}
	}
}

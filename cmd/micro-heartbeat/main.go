// micro-heartbeat — Proxmox 微型主機心跳 daemon（Go 版，取代 remote_scripts 的 heartbeat.py）。
//
// 常駐執行：每 HEARTBEAT_INTERVAL 秒透過 pvesh 抓 VM/CT 狀態，組 JSON 寫入 Redis
// （帶 TTL）。key 不存在即視為離線，因此 interval 必須小於 TTL。
//
// payload 格式與 heartbeat.py 完全一致，Laravel 端 MicroHostStatus 不需更動。
package main

import (
	"context"
	"encoding/json"
	"log"
	"net"
	"os"
	"os/exec"
	"os/signal"
	"strconv"
	"syscall"
	"time"

	"github.com/redis/go-redis/v9"
)

// guest 是寫入 Redis 的 VM/CT 項目，欄位對齊 heartbeat.py。
type guest struct {
	ID     int    `json:"id"`
	Name   string `json:"name"`
	Type   string `json:"type"`
	Status string `json:"status"`
}

// payload 是 Redis key 的完整內容，欄位對齊 MicroHostStatus::full()。
type payload struct {
	Host     string  `json:"host"`
	LastSeen string  `json:"last_seen"`
	VMs      []guest `json:"vms"`
	CTs      []guest `json:"cts"`
	APIError string  `json:"api_error,omitempty"`
}

type config struct {
	redisAddr     string
	redisPassword string
	redisKey      string
	ttl           time.Duration
	interval      time.Duration
	pveNode       string
}

func loadConfig() config {
	host := os.Getenv("REDIS_HOST")
	if host == "" {
		log.Fatal("ERROR: REDIS_HOST is not set")
	}
	port := envOr("REDIS_PORT", "6379")

	// ttl / interval 必須 > 0：interval <= 0 會讓 time.NewTicker panic，
	// ttl <= 0 會讓 key 永不過期（dashboard 永遠顯示在線）。皆 fallback 預設值。
	ttl := time.Duration(envInt("REDIS_TTL", 120)) * time.Second
	if ttl <= 0 {
		ttl = 120 * time.Second
	}
	interval := time.Duration(envInt("HEARTBEAT_INTERVAL", 60)) * time.Second
	if interval <= 0 {
		interval = 60 * time.Second
	}

	return config{
		redisAddr:     net.JoinHostPort(host, port), // 正確處理 IPv4 / IPv6
		redisPassword: os.Getenv("REDIS_PASSWORD"),
		redisKey:      envOr("REDIS_KEY", "micro:online"),
		ttl:           ttl,
		interval:      interval,
		pveNode:       envOr("PVE_NODE", "pve"),
	}
}

func envOr(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func envInt(key string, def int) int {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			return n
		}
	}
	return def
}

func main() {
	cfg := loadConfig()

	rdb := redis.NewClient(&redis.Options{
		Addr:         cfg.redisAddr,
		Password:     cfg.redisPassword,
		DialTimeout:  5 * time.Second,
		ReadTimeout:  5 * time.Second,
		WriteTimeout: 5 * time.Second,
	})
	defer rdb.Close()

	hostname, _ := os.Hostname()

	// 收到 SIGTERM/SIGINT（systemd stop / 重啟）時優雅退出。
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()

	log.Printf("micro-heartbeat started: redis=%s key=%s interval=%s ttl=%s node=%s",
		cfg.redisAddr, cfg.redisKey, cfg.interval, cfg.ttl, cfg.pveNode)

	// 啟動即先寫一次，不等第一個 tick。
	beat(ctx, rdb, cfg, hostname)

	ticker := time.NewTicker(cfg.interval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			// 主動停止時刪 key，dashboard 立即反映離線（毋須等 TTL）。
			delCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
			if err := rdb.Del(delCtx, cfg.redisKey).Err(); err != nil {
				log.Printf("[warn] failed to delete key on shutdown: %v", err)
			}
			cancel()
			log.Println("micro-heartbeat stopped")
			return
		case <-ticker.C:
			beat(ctx, rdb, cfg, hostname)
		}
	}
}

// beat 抓一次狀態並寫入 Redis。
func beat(ctx context.Context, rdb *redis.Client, cfg config, hostname string) {
	vms, vmErr := getGuests(ctx, cfg.pveNode, "qemu")
	cts, ctErr := getGuests(ctx, cfg.pveNode, "lxc")

	p := payload{
		Host:     hostname,
		LastSeen: time.Now().UTC().Format(time.RFC3339),
		VMs:      vms,
		CTs:      cts,
	}
	if vmErr || ctErr {
		p.APIError = "unexpected_format"
	}

	data, err := json.Marshal(p)
	if err != nil {
		log.Printf("[error] marshal payload: %v", err)
		return
	}

	writeCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	if err := rdb.Set(writeCtx, cfg.redisKey, data, cfg.ttl).Err(); err != nil {
		log.Printf("[error] write redis: %v", err)
		return
	}

	status := "[ok]"
	if p.APIError != "" {
		status = "[api_error] " + p.APIError
	}
	log.Printf("%s %s  vms=%d  cts=%d", status, p.LastSeen, len(vms), len(cts))
}

// pveGuest 是 pvesh 回傳的原始項目；vmid 用 json.Number 以免浮點失真。
type pveGuest struct {
	Vmid   json.Number `json:"vmid"`
	Name   string      `json:"name"`
	Status string      `json:"status"`
}

// getGuests 抓某 node 的 qemu 或 lxc 清單。
// 回傳 (清單, 是否格式錯誤)；命令失敗或結構非預期時回 ([], true)。
// pvesh 綁 10s timeout 並接 ctx：避免 Proxmox API 無回應時卡死主迴圈、
// 並讓 SIGTERM 能即時中止卡住的指令以利優雅關閉。
func getGuests(ctx context.Context, node, kind string) ([]guest, bool) {
	path := "/nodes/" + node + "/" + kind
	cmdCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	out, err := exec.CommandContext(cmdCtx, "pvesh", "get", path, "--output-format", "json").Output()
	if err != nil {
		log.Printf("[warn] pvesh %s: %v", path, err)
		return []guest{}, true
	}

	var raw []pveGuest
	if err := json.Unmarshal(out, &raw); err != nil {
		log.Printf("[warn] pvesh %s: unexpected format: %v", path, err)
		return []guest{}, true
	}

	typeName := "qemu"
	prefix := "vm-"
	if kind == "lxc" {
		typeName = "lxc"
		prefix = "ct-"
	}

	result := make([]guest, 0, len(raw))
	for _, item := range raw {
		if item.Vmid == "" {
			continue
		}
		id, err := strconv.Atoi(item.Vmid.String())
		if err != nil {
			continue
		}
		name := item.Name
		if name == "" {
			name = prefix + item.Vmid.String()
		}
		status := item.Status
		if status == "" {
			status = "unknown"
		}
		result = append(result, guest{ID: id, Name: name, Type: typeName, Status: status})
	}
	return result, false
}

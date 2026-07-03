// ticket-rush — SSE 廣播 + 多人同時搶票 + 候補號碼牌示範（獨立 Go server，與 ws-lab 成對：那個是 WebSocket）
//
//   - 整頁一條 SSE(/events) 收廣播，fan-out 給每個「搶票人」格子 + 一個「觀眾」格子
//     （瀏覽器對同源 HTTP/1.1 僅約 6 條並發連線，故不讓每格各開一條 SSE）
//   - 「全體同時搶票」= 所有搶票人同時打 POST /buy；server 端 ticketStore 單一消費者
//     把併發請求串行化 → 5 張票不會超賣，搶輸的人「自動進候補」拿號碼牌（FIFO）
//   - 中票者可「退票」：不立即釋出，先進入 3 秒緩衝（releasing），期滿才真正釋座、
//     由候補隊首遞補中票；期滿判定由 ticketStore 單一 goroutine 每 250ms 掃 ticker 完成
//   - 讀寫分離：寫權威(ticketStore)把狀態物化到 atomic projection；對外不整包廣播，
//     改送「合流後的 manifest（各段內容雜湊）」，前端比對後只 GET 變動的分段
//     （summary / winners / queue 各頁，帶 ETag、可被 CDN 快取 / 回 304）
//
// 設計重點：共享狀態（broadcaster map / 票數 / 中票者 / 候補隊列）各由自己的 goroutine 獨佔，
// 其他 goroutine 一律經 channel 互動（reg/unreg/msg、buy/release/reset）；寫權威另把狀態
// 物化到 atomic projection 供讀端無鎖讀取，無 data race。
//
// 跑法：go run ./cmd/ticket-rush  → 開 http://localhost:8099
// TODO：之後比照 ws-lab 接進 Laravel（Vue 頁 + routes.ts + nginx 反代，SSE 不經 PHP）。
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"hash/fnv"
	"net/http"
	"strconv"
	"sync/atomic"
	"time"
)

// ---- Broadcaster：共享 map 只由 Run 存取 ----

type Broadcaster struct {
	clients map[chan string]bool
	regCh   chan chan string
	unregCh chan chan string
	msgCh   chan string
	done    chan struct{}
}

func newBroadcaster() *Broadcaster {
	return &Broadcaster{
		clients: make(map[chan string]bool),
		regCh:   make(chan chan string),
		unregCh: make(chan chan string),
		msgCh:   make(chan string, 100),
		done:    make(chan struct{}),
	}
}

func (b *Broadcaster) Register(c chan string) { b.clients[c] = true }

func (b *Broadcaster) Unregister(c chan string) {
	if _, ok := b.clients[c]; ok {
		delete(b.clients, c)
		close(c)
	}
}

func (b *Broadcaster) Broadcast(msg string) {
	for ch := range b.clients {
		select {
		case ch <- msg:
		default:
		}
	}
}

func (b *Broadcaster) Run(ctx context.Context) {
	defer close(b.done)
	for {
		select {
		case c := <-b.regCh:
			b.Register(c)
			b.Broadcast(presence(len(b.clients)))
		case c := <-b.unregCh:
			b.Unregister(c)
			b.Broadcast(presence(len(b.clients)))
		case msg := <-b.msgCh:
			b.Broadcast(msg)
		case <-ctx.Done():
			for ch := range b.clients {
				b.Unregister(ch)
			}
			return
		}
	}
}

// ---- 訊息格式：JSON 給前端渲染 ----

func presence(n int) string {
	out, _ := json.Marshal(map[string]any{"type": "presence", "online": n})
	return string(out)
}

func sysEvent(kind, text string, remaining int) string {
	out, _ := json.Marshal(map[string]any{"type": kind, "text": text, "remaining": remaining})
	return string(out)
}

// ticket 中票事件：讓前端把訊息對應到該搶票人的格子
func ticketEvent(buyer string, remaining int, text string) string {
	out, _ := json.Marshal(map[string]any{
		"type": "ticket", "buyer": buyer, "remaining": remaining, "text": text,
	})
	return string(out)
}

// waitlist：搶輸自動進候補，帶號碼牌 position
func waitlistEvent(buyer string, position, queueLen int, text string) string {
	out, _ := json.Marshal(map[string]any{
		"type": "waitlist", "buyer": buyer, "position": position, "queueLen": queueLen, "text": text,
	})
	return string(out)
}

// releasing：退票進入緩衝，releaseAt 為期滿時間（unix ms）供前端倒數
func releasingEvent(buyer string, releaseAt int64, text string) string {
	out, _ := json.Marshal(map[string]any{
		"type": "releasing", "buyer": buyer, "releaseAt": releaseAt, "text": text,
	})
	return string(out)
}

// promote：候補隊首遞補中票
func promoteEvent(buyer string, remaining int, text string) string {
	out, _ := json.Marshal(map[string]any{
		"type": "promote", "buyer": buyer, "remaining": remaining, "text": text,
	})
	return string(out)
}

// left：候補者自主離開隊伍（後面號碼牌自動遞補）
func leftEvent(buyer, text string) string {
	out, _ := json.Marshal(map[string]any{"type": "left", "buyer": buyer, "text": text})
	return string(out)
}

// payEvent：付款室敘事事件（payopen / paying / paid），狀態本身走 winners 分段
func payEvent(kind, buyer string, deadline int64, text string) string {
	m := map[string]any{"type": kind, "buyer": buyer, "text": text}
	if deadline > 0 {
		m["deadline"] = deadline
	}
	out, _ := json.Marshal(m)
	return string(out)
}

// ---- 讀側物化視圖 + 內容定址分段 ----
//
// 寫權威每次異動就把新 snapshot 存進 projection；HTTP 讀端無鎖 Load（讀寫分離）。
// 對外不整包廣播：狀態切成分段（summary / winners / queue 各頁），各段以內容雜湊定址；
// SSE 只送「合流後的 manifest（各段 hash）」，前端比對後只 GET 變動的段（帶 ETag，可 304 / CDN 快取）。

type winnerView struct {
	Name        string `json:"name"`
	Releasing   bool   `json:"releasing"`
	ReleaseAt   int64  `json:"releaseAt,omitempty"`   // unix ms；releasing 時才有
	Pay         string `json:"pay"`                   // queued | open | paying | paid
	PayDeadline int64  `json:"payDeadline,omitempty"` // unix ms；open(10s) / paying(5s) 時才有
}

type snapshot struct {
	Remaining int          `json:"remaining"`
	Winners   []winnerView `json:"winners"`
	Queue     []string     `json:"queue"`
}

// projection：讀側物化視圖，寫權威更新、讀端無鎖讀取
var projection atomic.Pointer[snapshot]

const queuePageSize = 50 // 候補分頁大小

func hashStr(b []byte) string {
	h := fnv.New64a()
	_, _ = h.Write(b)
	return fmt.Sprintf("%016x", h.Sum64())
}

// segments：把 snapshot 切成可獨立定址的分段 JSON
//   - summary：共享彙總（remaining / queueLen / soldOut），最常被讀、可被 CDN 快取
//   - winners：目前持票者（≤ totalTickets）
//   - queuePages：候補名單分頁（無界資料，切頁；候補為空也給 page 0）
func segments(s snapshot) (summary, winners []byte, queuePages [][]byte) {
	summary, _ = json.Marshal(map[string]any{
		"remaining": s.Remaining, "queueLen": len(s.Queue), "soldOut": s.Remaining <= 0,
	})
	winners, _ = json.Marshal(s.Winners)
	for i := 0; i < len(s.Queue); i += queuePageSize {
		end := i + queuePageSize
		if end > len(s.Queue) {
			end = len(s.Queue)
		}
		p, _ := json.Marshal(map[string]any{
			"page": i / queuePageSize, "pageSize": queuePageSize, "total": len(s.Queue), "names": s.Queue[i:end],
		})
		queuePages = append(queuePages, p)
	}
	if len(queuePages) == 0 { // 候補為空也給 page 0，讓前端能讀到「空隊列」
		p, _ := json.Marshal(map[string]any{
			"page": 0, "pageSize": queuePageSize, "total": 0, "names": []string{},
		})
		queuePages = append(queuePages, p)
	}
	return
}

// manifestEvent：合流後對外廣播的版本清單，只帶各段內容雜湊（O(頁數)，不含資料本身）
func manifestEvent(s snapshot) string {
	summary, winners, pages := segments(s)
	ph := make([]string, len(pages))
	for i, p := range pages {
		ph[i] = hashStr(p)
	}
	out, _ := json.Marshal(map[string]any{
		"type": "manifest", "summary": hashStr(summary), "winners": hashStr(winners), "queuePages": ph,
	})
	return string(out)
}

// serveSegment：以內容雜湊當 ETag，支援 If-None-Match → 304（讓分段可被 CDN / 瀏覽器快取）
func serveSegment(w http.ResponseWriter, r *http.Request, body []byte) {
	etag := `"` + hashStr(body) + `"`
	w.Header().Set("ETag", etag)
	w.Header().Set("Cache-Control", "no-cache") // 可快取，但每次需 revalidate
	if r.Header.Get("If-None-Match") == etag {
		w.WriteHeader(http.StatusNotModified)
		return
	}
	w.Header().Set("Content-Type", "application/json")
	_, _ = w.Write(body)
}

// ---- 搶票 / 退票 / 候補：單一消費者，併發請求天然被串行化，不會超賣 ----

const (
	totalTickets = 5
	releaseDelay = 3 * time.Second        // 退票後緩衝：不立即釋出座位
	sweepEvery   = 250 * time.Millisecond // 掃描到期退票 / 付款室的頻率
	payHold      = 10 * time.Second       // 待付款：放行後未按付款鍵的保留時限（逾時釋出票源）
	payProcess   = 5 * time.Second        // 付款中：按下付款鍵後的處理時間
)

// payStage：中票後的付款子狀態（付款室同時只放行 1 人）
type payStage int

const (
	payQueued payStage = iota // 等待付款：排隊等付款室放行
	payOpen                   // 待付款：已放行，10s hold 倒數，可按付款
	payPaying                 // 付款中：已按付款，5s 處理倒數
	payPaid                   // 已付款
)

func (p payStage) String() string {
	switch p {
	case payOpen:
		return "open"
	case payPaying:
		return "paying"
	case payPaid:
		return "paid"
	default:
		return "queued"
	}
}

// holder：一張已售出的票。releasing = 按了退票、正在 releaseAt 倒數；pay = 付款階段
type holder struct {
	name        string
	releasing   bool
	releaseAt   time.Time
	pay         payStage
	payDeadline time.Time // payOpen: 10s hold 到期；payPaying: 5s 處理到期
}

func ticketStore(
	ctx context.Context,
	buyCh chan string,
	releaseCh chan string,
	leaveCh chan string,
	payCh chan string,
	resetCh chan struct{},
	msgCh chan string,
) {
	var winners []holder // 依中票序；releasing 中的仍佔位，期滿才移除
	var queue []string   // 候補 FIFO，號碼牌 = index+1

	ticker := time.NewTicker(sweepEvery)
	defer ticker.Stop()

	snap := func() snapshot {
		ws := make([]winnerView, len(winners))
		for i, w := range winners {
			v := winnerView{Name: w.name, Releasing: w.releasing, Pay: w.pay.String()}
			if w.releasing {
				v.ReleaseAt = w.releaseAt.UnixMilli()
			}
			if w.pay == payOpen || w.pay == payPaying {
				v.PayDeadline = w.payDeadline.UnixMilli()
			}
			ws[i] = v
		}
		return snapshot{
			Remaining: totalTickets - len(winners),
			Winners:   ws,
			Queue:     append([]string(nil), queue...),
		}
	}

	dirty := false
	// publish：更新讀側物化視圖並標記待廣播；manifest 由 ticker 一次送出（合流）
	publish := func() {
		s := snap()
		projection.Store(&s)
		dirty = true
	}

	winnerIndex := func(name string) int {
		for i, w := range winners {
			if w.name == name {
				return i
			}
		}
		return -1
	}
	queueIndex := func(name string) int {
		for i, n := range queue {
			if n == name {
				return i
			}
		}
		return -1
	}
	// payOccupant：目前佔用付款室的中票者 index（open/paying 且非退票中；至多 1 人），無則 -1
	payOccupant := func() int {
		for i, w := range winners {
			if !w.releasing && (w.pay == payOpen || w.pay == payPaying) {
				return i
			}
		}
		return -1
	}
	// freeSeat：座位釋出（退票到期 or 逾時未付）→ 候補隊首遞補（新座位 pay=queued），無候補則票數回升
	freeSeat := func(releasedName, reason string) {
		if len(queue) > 0 {
			head := queue[0]
			queue = queue[1:]
			winners = append(winners, holder{name: head}) // pay 預設 queued，重新排付款
			msgCh <- promoteEvent(head, totalTickets-len(winners),
				fmt.Sprintf("🎫 候補遞補：「%s」補上「%s」（%s）的座位", head, releasedName, reason))
		} else {
			msgCh <- sysEvent("released",
				fmt.Sprintf("「%s」釋出票源（%s），剩 %d 張", releasedName, reason, totalTickets-len(winners)),
				totalTickets-len(winners))
		}
	}

	for {
		select {
		case buyer := <-buyCh:
			if winnerIndex(buyer) >= 0 {
				break // 已中票（含退票緩衝中）→ 忽略
			}
			if qi := queueIndex(buyer); qi >= 0 {
				msgCh <- waitlistEvent(buyer, qi+1, len(queue),
					fmt.Sprintf("「%s」已在候補 #%d", buyer, qi+1))
				break // 已在候補 → 重播號碼牌，不重複排
			}
			if len(winners) < totalTickets {
				winners = append(winners, holder{name: buyer})
				remaining := totalTickets - len(winners)
				msgCh <- ticketEvent(buyer, remaining,
					fmt.Sprintf("「%s」搶到第 %d 張，剩 %d 張", buyer, len(winners), remaining))
				if remaining == 0 {
					msgCh <- sysEvent("soldout", "🎉 5 張票已售完！之後搶的自動進候補", 0)
				}
			} else {
				queue = append(queue, buyer)
				msgCh <- waitlistEvent(buyer, len(queue), len(queue),
					fmt.Sprintf("「%s」來晚了，售完 → 候補 #%d", buyer, len(queue)))
			}
			publish()

		case buyer := <-releaseCh:
			i := winnerIndex(buyer)
			if i < 0 || winners[i].releasing || winners[i].pay == payPaid {
				break // 不是中票者、已在退票緩衝中、或已付款（不可退）
			}
			winners[i].releasing = true
			winners[i].releaseAt = time.Now().Add(releaseDelay)
			msgCh <- releasingEvent(buyer, winners[i].releaseAt.UnixMilli(),
				fmt.Sprintf("「%s」按下退票，%d 秒後釋出座位…", buyer, int(releaseDelay.Seconds())))
			publish()

		case buyer := <-payCh:
			i := winnerIndex(buyer)
			if i < 0 || winners[i].releasing || winners[i].pay != payOpen {
				break // 只有「待付款(open)」且非退票中才能按付款
			}
			winners[i].pay = payPaying
			winners[i].payDeadline = time.Now().Add(payProcess)
			msgCh <- payEvent("paying", buyer, winners[i].payDeadline.UnixMilli(),
				fmt.Sprintf("💳「%s」按下付款，處理中（%d 秒）…", buyer, int(payProcess.Seconds())))
			publish()

		case buyer := <-leaveCh:
			qi := queueIndex(buyer)
			if qi < 0 {
				break // 不在候補隊列中
			}
			queue = append(queue[:qi], queue[qi+1:]...) // 即時移除；後面號碼牌自動前移
			msgCh <- leftEvent(buyer, fmt.Sprintf("「%s」離開候補隊伍", buyer))
			publish()

		case <-ticker.C:
			now := time.Now()

			// (a) 退票緩衝到期 → 釋座 + 候補遞補
			for i := 0; i < len(winners); {
				w := winners[i]
				if !w.releasing || now.Before(w.releaseAt) {
					i++
					continue
				}
				winners = append(winners[:i], winners[i+1:]...)
				freeSeat(w.name, "退票")
				publish()
				// 不 i++：切片已在 i 處縮短，遞補者 append 於尾端稍後略過
			}

			// (b) 付款室：結算目前佔用者（付款中→已付款；待付款逾時→釋出票源）
			if occ := payOccupant(); occ >= 0 {
				w := winners[occ]
				if w.pay == payPaying && !now.Before(w.payDeadline) {
					winners[occ].pay = payPaid
					msgCh <- payEvent("paid", w.name, 0, fmt.Sprintf("✅「%s」付款完成", w.name))
					publish()
				} else if w.pay == payOpen && !now.Before(w.payDeadline) {
					winners = append(winners[:occ], winners[occ+1:]...) // 逾時未付 → 立即釋出票源
					freeSeat(w.name, "逾時未付款")
					publish()
				}
			}

			// (c) 付款室空著 → 放行最早的「等待付款」者（10s hold）
			if payOccupant() < 0 {
				for i := range winners {
					if winners[i].pay == payQueued && !winners[i].releasing {
						winners[i].pay = payOpen
						winners[i].payDeadline = now.Add(payHold)
						msgCh <- payEvent("payopen", winners[i].name, winners[i].payDeadline.UnixMilli(),
							fmt.Sprintf("💳 輪到「%s」付款，請 %d 秒內完成", winners[i].name, int(payHold.Seconds())))
						publish()
						break
					}
				}
			}

			if dirty { // 合流：一個 tick 內累積的異動，只送一則 manifest
				msgCh <- manifestEvent(*projection.Load())
				dirty = false
			}

		case <-resetCh:
			winners = nil
			queue = nil
			msgCh <- sysEvent("info", "🔄 已重置，恢復 5 張票", totalTickets)
			publish()

		case <-ctx.Done():
			return
		}
	}
}

func main() {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	b := newBroadcaster()
	go b.Run(ctx)

	// 讀側物化視圖起始值（空狀態），讓 ticketStore 尚未動作前的讀端也有東西可讀
	projection.Store(&snapshot{Remaining: totalTickets, Winners: []winnerView{}, Queue: []string{}})

	buyCh := make(chan string)
	releaseCh := make(chan string)
	leaveCh := make(chan string)
	payCh := make(chan string)
	resetCh := make(chan struct{})
	go ticketStore(ctx, buyCh, releaseCh, leaveCh, payCh, resetCh, b.msgCh)

	// 全域遞增發號，讓每個分頁的角色名字唯一（跨分頁接續往上加，不撞名）
	// 買家與觀眾各走一條序列，互不干擾
	var buyerSeq, spectatorSeq atomic.Uint64

	mux := http.NewServeMux()

	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/" {
			http.NotFound(w, r)
			return
		}
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		_, _ = w.Write([]byte(indexHTML))
	})

	mux.HandleFunc("/events", func(w http.ResponseWriter, r *http.Request) {
		flusher, ok := w.(http.Flusher)
		if !ok {
			http.Error(w, "streaming unsupported", http.StatusInternalServerError)
			return
		}
		w.Header().Set("Content-Type", "text/event-stream")
		w.Header().Set("Cache-Control", "no-cache")
		w.Header().Set("Connection", "keep-alive")

		client := make(chan string, 50)
		b.regCh <- client
		defer func() {
			select {
			case b.unregCh <- client:
			case <-b.done:
			}
		}()

		// 連上線立刻送目前 manifest，前端據此拉取各分段（連線對帳）
		fmt.Fprintf(w, "data: %s\n\n", manifestEvent(*projection.Load()))
		flusher.Flush()

		for {
			select {
			case msg, ok := <-client:
				if !ok {
					return
				}
				if _, err := fmt.Fprintf(w, "data: %s\n\n", msg); err != nil {
					return // 客戶端已斷線 → 立即結束、跑 defer 註銷
				}
				flusher.Flush()
			case <-r.Context().Done():
				return
			}
		}
	})

	mux.HandleFunc("/join", func(w http.ResponseWriter, r *http.Request) {
		seq := &buyerSeq // 原子遞增，跨分頁唯一
		if r.URL.Query().Get("role") == "spectator" {
			seq = &spectatorSeq // 觀眾走自己的序列
		}
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprintf(w, `{"id":%d}`, seq.Add(1))
	})

	// ---- 讀側分段：各段帶 ETag（內容雜湊）；前端依 manifest 只 GET 變動的段 ----
	mux.HandleFunc("/state/summary", func(w http.ResponseWriter, r *http.Request) {
		summary, _, _ := segments(*projection.Load())
		serveSegment(w, r, summary)
	})
	mux.HandleFunc("/state/winners", func(w http.ResponseWriter, r *http.Request) {
		_, winners, _ := segments(*projection.Load())
		serveSegment(w, r, winners)
	})
	mux.HandleFunc("/state/queue", func(w http.ResponseWriter, r *http.Request) {
		_, _, pages := segments(*projection.Load())
		p := 0
		if q := r.URL.Query().Get("page"); q != "" {
			var err error
			if p, err = strconv.Atoi(q); err != nil {
				http.Error(w, "invalid page parameter", http.StatusBadRequest)
				return
			}
		}
		if p < 0 || p >= len(pages) {
			http.Error(w, "page out of range", http.StatusNotFound)
			return
		}
		serveSegment(w, r, pages[p])
	})

	mux.HandleFunc("/buy", func(w http.ResponseWriter, r *http.Request) {
		buyer := r.URL.Query().Get("name")
		if buyer == "" {
			http.Error(w, "name required", http.StatusBadRequest) // 空名會撞掉 ticketStore 的唯一鍵
			return
		}
		select {
		case buyCh <- buyer:
			w.WriteHeader(http.StatusNoContent)
		case <-r.Context().Done():
		}
	})

	mux.HandleFunc("/release", func(w http.ResponseWriter, r *http.Request) {
		name := r.URL.Query().Get("name")
		if name == "" {
			http.Error(w, "name required", http.StatusBadRequest)
			return
		}
		select {
		case releaseCh <- name:
			w.WriteHeader(http.StatusNoContent)
		case <-r.Context().Done():
		}
	})

	mux.HandleFunc("/leave", func(w http.ResponseWriter, r *http.Request) {
		name := r.URL.Query().Get("name")
		if name == "" {
			http.Error(w, "name required", http.StatusBadRequest)
			return
		}
		select {
		case leaveCh <- name:
			w.WriteHeader(http.StatusNoContent)
		case <-r.Context().Done():
		}
	})

	mux.HandleFunc("/pay", func(w http.ResponseWriter, r *http.Request) {
		name := r.URL.Query().Get("name")
		if name == "" {
			http.Error(w, "name required", http.StatusBadRequest)
			return
		}
		select {
		case payCh <- name:
			w.WriteHeader(http.StatusNoContent)
		case <-r.Context().Done():
		}
	})

	mux.HandleFunc("/reset", func(w http.ResponseWriter, r *http.Request) {
		select {
		case resetCh <- struct{}{}:
			w.WriteHeader(http.StatusNoContent)
		case <-r.Context().Done():
		}
	})

	addr := ":8099"
	// 刻意不設 WriteTimeout：SSE 是長連線串流，設了會被硬切斷。
	// 用 ReadHeaderTimeout 擋 Slowloris、IdleTimeout 收沒在用的 keep-alive 連線。
	server := &http.Server{
		Addr:              addr,
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
		IdleTimeout:       120 * time.Second,
	}
	fmt.Println("Listening on http://localhost" + addr)
	if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		fmt.Println("server error:", err)
	}
}

const indexHTML = `<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ticket-rush · 多人同時搶票 + 候補</title>
<style>
  :root { --bg:#0d1117; --panel:#161b22; --line:#30363d; --green:#3fb950; --red:#f85149;
          --blue:#58a6ff; --amber:#d29922; --text:#e6edf3; --muted:#8b949e; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:ui-monospace,Menlo,Consolas,monospace; background:var(--bg); color:var(--text); }
  .wrap { max-width:1200px; margin:0 auto; padding:20px 16px 60px; }
  h1 { font-size:20px; margin:0 0 4px; }
  .sub { color:var(--muted); font-size:13px; margin-bottom:16px; line-height:1.6; }
  .bar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:18px; }
  .pill { background:var(--panel); border:1px solid var(--line); border-radius:999px; padding:6px 14px; font-size:13px; }
  .pill b { color:var(--green); }
  .dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:6px; background:var(--red); }
  .dot.on { background:var(--green); box-shadow:0 0 8px var(--green); }
  button { background:var(--green); border:0; color:#04240f; font-weight:700; border-radius:8px; padding:9px 16px; cursor:pointer; font:inherit; }
  button.ghost { background:transparent; border:1px solid var(--line); color:var(--text); font-weight:400; }
  button.big { background:var(--amber); color:#241a02; font-size:15px; padding:11px 22px; }
  button.icon { padding:9px; width:40px; font-size:16px; line-height:1; text-align:center; }
  .bar button { height:38px; display:inline-flex; align-items:center; justify-content:center; } /* 同排等高 */
  button:disabled { opacity:.4; cursor:not-allowed; }
  button:active:not(:disabled) { transform:translateY(1px); }

  .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:12px; }
  .card { background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:12px; display:flex; flex-direction:column; min-height:200px; transition:border-color .2s, box-shadow .2s; }
  .card.spectator { border-color:var(--blue); grid-column:1/-1; }
  .card.win { border-color:var(--green); box-shadow:0 0 0 1px var(--green) inset; }
  .card.waiting { border-color:var(--amber); }
  .card.releasing { border-color:var(--amber); box-shadow:0 0 0 1px var(--amber) inset; }
  .card.paying { border-color:var(--blue); box-shadow:0 0 0 1px var(--blue) inset; }
  .chead { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:8px; }
  .cname { font-weight:700; }
  .spectator .cname { color:var(--blue); }
  .badge { font-size:12px; padding:2px 8px; border-radius:999px; background:#0a0e14; border:1px solid var(--line); color:var(--muted); white-space:nowrap; }
  .badge.win { color:var(--green); border-color:var(--green); }
  .badge.wait { color:var(--amber); border-color:var(--amber); }
  .badge.late { color:var(--red); border-color:var(--red); }
  .badge.pay { color:var(--blue); border-color:var(--blue); }
  .buyBtn, .altBtn { width:100%; margin-bottom:8px; }
  .feed { background:#0a0e14; border:1px solid var(--line); border-radius:8px; padding:8px; flex:1; overflow:auto; font-size:12px; line-height:1.6; max-height:170px; }
  .spectator .feed { max-height:240px; }
  .ln { padding:1px 0; }
  .ln .t { color:var(--muted); margin-right:6px; }
  .msg { color:var(--text); }
  .msg.win, .msg.promote { color:var(--green); }
  .msg.wait, .msg.releasing, .msg.info { color:var(--amber); }
  .msg.released { color:var(--muted); }
  .msg.soldout { color:var(--red); font-weight:700; }
  .msg.pay { color:var(--blue); }
  .msg.self { font-weight:700; }
</style>
</head>
<body>
<div class="wrap">
  <h1>🎟️ ticket-rush — 多人同時搶票 + 候補號碼牌（5 張票）</h1>
  <div class="sub">每格 = 一個搶票人（最多 12），最下方是只看不搶的觀眾。所有人共用一條 SSE 收廣播；「全體同時搶票」讓大家同時打 API，server 串行化 → 不超賣、搶輸的自動進候補（FIFO）。中票者可「退票」：<b>不立即釋出，3 秒緩衝後</b>才釋座、由候補隊首遞補。</div>

  <div class="bar">
    <span class="pill"><span id="dot" class="dot"></span><span id="status">連線中…</span></span>
    <span class="pill">剩餘票 <b id="remaining">…</b> / 5</span>
    <span class="pill">搶票人 <b id="count">0</b> / 12</span>
    <span class="pill">候補 <b id="wait">0</b> 人</span>
    <span class="pill">分頁連線 <b id="online">0</b></span>
  </div>

  <div class="bar">
    <button id="addBtn" class="ghost icon" title="新增搶票人" aria-label="新增搶票人">＋</button>
    <button id="delBtn" class="ghost icon" title="減少搶票人" aria-label="減少搶票人">－</button>
    <button id="rushBtn" class="big">⚡ 全體同時搶票！</button>
    <button id="resetBtn" class="ghost icon" title="重置 5 張票" aria-label="重置 5 張票">🔄</button>
  </div>

  <div id="grid" class="grid"></div>
</div>

<script>
  const MAX = 12;
  const grid = document.getElementById('grid');
  const onlineEl = document.getElementById('online');
  const remainingEl = document.getElementById('remaining');
  const countEl = document.getElementById('count');
  const waitEl = document.getElementById('wait');
  const statusEl = document.getElementById('status');
  const dot = document.getElementById('dot');

  const buyers = []; // { name, cardEl, feedEl, badgeEl, btnEl, state, releaseAt }
  let spectatorFeed = null;

  function nowTime() { return new Date().toLocaleTimeString(); }

  function pushLine(feedEl, cls, text) {
    const div = document.createElement('div');
    div.className = 'ln';
    const timeSpan = document.createElement('span');
    timeSpan.className = 't';
    timeSpan.textContent = nowTime();
    const msgSpan = document.createElement('span');
    msgSpan.className = cls;      // cls 由我方控制（safe）；text 含使用者名字 → 用 textContent 防 XSS
    msgSpan.textContent = text;
    div.appendChild(timeSpan);
    div.appendChild(msgSpan);
    feedEl.appendChild(div);
    feedEl.scrollTop = feedEl.scrollHeight;
  }

  function makeBuyerCard(name) {
    const card = document.createElement('div');
    card.className = 'card buyer';
    card.innerHTML =
      '<div class="chead"><span class="cname"></span><span class="badge">待命</span></div>' +
      '<button class="buyBtn">搶票</button>' +
      '<button class="altBtn ghost" style="display:none">退票</button>' +
      '<div class="feed"></div>';
    card.querySelector('.cname').textContent = name; // 用 textContent 賦值，不拼進 innerHTML
    const b = {
      name,
      cardEl: card,
      feedEl: card.querySelector('.feed'),
      badgeEl: card.querySelector('.badge'),
      btnEl: card.querySelector('.buyBtn'),   // 前進動作：搶票 / 加入候補 / 付款
      altEl: card.querySelector('.altBtn'),   // 退出動作：退票 / 離開候補
      state: 'idle',
      releaseAt: 0,
      payDeadline: 0,
      justReleased: false, // 剛退票完成 → 顯示「已退票」，再次動作即清除
    };
    b.btnEl.onclick = () => {
      if (b.state === 'idle') buy(b.name);
      else if (b.state === 'open') pay(b.name);
    };
    b.altEl.onclick = () => {
      if (b.state === 'waiting') leave(b.name);
      else release(b.name); // queued / open / paying → 退票
    };
    grid.appendChild(card);
    return b;
  }

  async function makeSpectator() {
    // 觀眾走自己的發號序列（觀眾N），與買家序列各自獨立
    const { id } = await fetch('/join?role=spectator', { method: 'POST' }).then(r => r.json());
    const card = document.createElement('div');
    card.className = 'card spectator';
    card.innerHTML =
      '<div class="chead"><span class="cname">👁 觀眾' + id + '（只看不搶）</span><span class="badge">旁觀中</span></div>' +
      '<div class="feed"></div>';
    grid.appendChild(card);
    spectatorFeed = card.querySelector('.feed');
  }

  async function addBuyer() {
    if (buyers.length >= MAX) return;
    // 跟 server 要一個全域唯一的編號 → 跨分頁接續往上加、不與別的分頁撞名
    const { id } = await fetch('/join', { method: 'POST' }).then(r => r.json());
    buyers.push(makeBuyerCard('買家' + id));
    if (spectatorFeed) grid.appendChild(spectatorFeed.closest('.card')); // 觀眾永遠排最後
    refreshButtons();
    renderCards(); // 依當前狀態套用新卡（如售完時顯示「加入候補」）
  }

  function removeBuyer() {
    if (buyers.length <= 1) return; // 至少留 1 個
    const b = buyers.pop();
    b.cardEl.remove();
    refreshButtons();
  }

  function refreshButtons() {
    countEl.textContent = buyers.length;
    document.getElementById('addBtn').disabled = buyers.length >= MAX;
    document.getElementById('delBtn').disabled = buyers.length <= 1;
  }

  function buy(name) { fetch('/buy?name=' + encodeURIComponent(name), { method: 'POST' }); }
  function release(name) { fetch('/release?name=' + encodeURIComponent(name), { method: 'POST' }); }
  function leave(name) { fetch('/leave?name=' + encodeURIComponent(name), { method: 'POST' }); }
  function pay(name) { fetch('/pay?name=' + encodeURIComponent(name), { method: 'POST' }); }

  function rush() {
    // 每人加 0~120ms 隨機延遲，模擬「手速不同」→ 到達順序隨機，中票與候補號碼牌都由 server 到達序決定
    buyers.forEach(b => setTimeout(() => buy(b.name), Math.random() * 120));
  }

  // ---- 讀側：SSE 只送合流後的 manifest（各段 hash）；前端比對後只 GET 變動的分段 ----
  const seg = { summary: '', winners: '', queuePages: [] };            // 上次看到的各段 hash
  const state = { remaining: 5, queueLen: 0, winners: [], queuePages: [] };

  function fetchJSON(url) {
    return fetch(url).then(r => (r.ok ? r.json() : null));
  }

  async function onManifest(m) {
    const jobs = [];
    if (m.summary !== seg.summary) {
      jobs.push(fetchJSON('/state/summary').then(d => {
        if (d) { state.remaining = d.remaining; state.queueLen = d.queueLen; }
        seg.summary = m.summary;
      }));
    }
    if (m.winners !== seg.winners) {
      jobs.push(fetchJSON('/state/winners').then(d => { state.winners = d || []; seg.winners = m.winners; }));
    }
    const pages = m.queuePages || [];
    for (let p = 0; p < pages.length; p++) {
      if (pages[p] !== seg.queuePages[p]) {
        jobs.push(fetchJSON('/state/queue?page=' + p).then(d => { state.queuePages[p] = d ? d.names : []; }));
      }
    }
    await Promise.all(jobs);
    state.queuePages.length = pages.length;                            // 頁數變少時截斷
    seg.queuePages = pages.slice();
    renderCards();
  }

  // 依 state 重畫每格 badge / 按鈕（唯一真實來源；資料來自拉取的分段）
  function renderCards() {
    const queue = state.queuePages.flat();
    remainingEl.textContent = state.remaining;
    waitEl.textContent = state.queueLen;
    const winBy = {};
    (state.winners || []).forEach(w => { winBy[w.name] = w; });
    const qPos = {};
    queue.forEach((n, i) => { qPos[n] = i + 1; });

    const soldOut = state.remaining <= 0;
    const show = (el, text, disabled) => { el.style.display = ''; el.textContent = text; el.disabled = !!disabled; };
    const hide = (el) => { el.style.display = 'none'; };
    buyers.forEach(b => {
      const w = winBy[b.name];
      const pos = qPos[b.name];
      b.cardEl.classList.remove('win', 'waiting', 'releasing', 'paying');
      if (w && w.releasing) {                        // 退票緩衝中（倒數）
        b.state = 'releasing'; b.releaseAt = w.releaseAt; b.justReleased = false;
        b.cardEl.classList.add('releasing');
        hide(b.btnEl); hide(b.altEl);                // badge 由倒數更新
      } else if (w && w.pay === 'paid') {            // 已付款（終態）
        b.state = 'paid'; b.releaseAt = 0; b.justReleased = false;
        b.cardEl.classList.add('win');
        b.badgeEl.textContent = '✅ 已付款'; b.badgeEl.className = 'badge win';
        hide(b.btnEl); hide(b.altEl);
      } else if (w && w.pay === 'paying') {          // 付款中（5s 倒數）
        b.state = 'paying'; b.payDeadline = w.payDeadline; b.releaseAt = 0; b.justReleased = false;
        b.cardEl.classList.add('paying');
        hide(b.btnEl); show(b.altEl, '退票', false); // badge 由倒數更新
      } else if (w && w.pay === 'open') {            // 待付款（10s hold，可付款/退票）
        b.state = 'open'; b.payDeadline = w.payDeadline; b.releaseAt = 0; b.justReleased = false;
        b.cardEl.classList.add('paying');
        show(b.btnEl, '付款', false); show(b.altEl, '退票', false); // badge 由倒數更新
      } else if (w) {                                // 等待付款（排隊等付款室）
        b.state = 'queued'; b.releaseAt = 0; b.payDeadline = 0; b.justReleased = false;
        b.cardEl.classList.add('win');
        b.badgeEl.textContent = '⌛ 等待付款'; b.badgeEl.className = 'badge wait';
        hide(b.btnEl); show(b.altEl, '退票', false);
      } else if (pos) {                              // 候補
        b.state = 'waiting'; b.releaseAt = 0; b.payDeadline = 0; b.justReleased = false;
        b.cardEl.classList.add('waiting');
        b.badgeEl.textContent = '🎫 候補 #' + pos; b.badgeEl.className = 'badge wait';
        hide(b.btnEl); show(b.altEl, '離開候補', false);
      } else {                                       // 待命 / 已退票
        b.state = 'idle'; b.releaseAt = 0; b.payDeadline = 0;
        b.badgeEl.textContent = b.justReleased ? '↩ 已退票' : '待命';
        b.badgeEl.className = b.justReleased ? 'badge late' : 'badge';
        show(b.btnEl, soldOut ? '加入候補' : '搶票', false); hide(b.altEl);
      }
    });
    paintCountdowns(); // 立即畫一次倒數，避免等下一個 250ms tick
  }

  // 倒數：releasing(退票中) / open(待付款 10s) / paying(付款中 5s) 每 250ms 重畫 badge
  function paintCountdowns() {
    const now = Date.now();
    buyers.forEach(b => {
      if (b.state === 'releasing' && b.releaseAt) {
        const s = Math.max(0, Math.ceil((b.releaseAt - now) / 1000));
        b.badgeEl.textContent = s > 0 ? ('⏳ 退票中 ' + s + 's') : '⏳ 釋出中…';
        b.badgeEl.className = 'badge late';
      } else if (b.state === 'open' && b.payDeadline) {
        const s = Math.max(0, Math.ceil((b.payDeadline - now) / 1000));
        b.badgeEl.textContent = '🕐 待付款 ' + s + 's';
        b.badgeEl.className = 'badge pay';
      } else if (b.state === 'paying' && b.payDeadline) {
        const s = Math.max(0, Math.ceil((b.payDeadline - now) / 1000));
        b.badgeEl.textContent = s > 0 ? ('💳 付款中 ' + s + 's') : '💳 完成中…';
        b.badgeEl.className = 'badge pay';
      }
    });
  }
  setInterval(paintCountdowns, 250);

  function feedLine(m, cls) {
    buyers.forEach(b => pushLine(b.feedEl, (m.buyer && b.name === m.buyer) ? cls + ' self' : cls, m.text));
    if (spectatorFeed) pushLine(spectatorFeed, cls, m.text);
  }

  function clearFeeds() {
    buyers.forEach(b => { b.feedEl.innerHTML = ''; });
    if (spectatorFeed) spectatorFeed.innerHTML = '';
  }

  // ---- SSE：整頁一條，fan-out 到每格 ----
  const es = new EventSource('/events');
  es.onopen = () => { statusEl.textContent = '已連線'; dot.classList.add('on'); };
  es.onerror = () => { statusEl.textContent = '斷線（重連中）'; dot.classList.remove('on'); };
  es.onmessage = (e) => {
    const m = JSON.parse(e.data);
    switch (m.type) {
      case 'presence': onlineEl.textContent = m.online; return;
      case 'manifest': onManifest(m); return;                           // 版本清單 → 只拉變動的分段
      case 'info':     buyers.forEach(b => { b.justReleased = false; }); clearFeeds(); feedLine(m, 'msg info'); return; // 重置：狀態由後續 manifest 帶回
      case 'ticket':   feedLine(m, 'msg win'); return;                  // 以下皆為敘事 feed 行
      case 'waitlist': feedLine(m, 'msg wait'); return;
      case 'releasing': { const b = buyers.find(x => x.name === m.buyer); if (b) b.justReleased = true; } feedLine(m, 'msg releasing'); return;
      case 'promote':  feedLine(m, 'msg promote'); return;
      case 'released': feedLine(m, 'msg released'); return;
      case 'left':     feedLine(m, 'msg released'); return;
      case 'soldout':  feedLine(m, 'msg soldout'); return;
      case 'payopen':  feedLine(m, 'msg pay'); return;                 // 付款室敘事（狀態走 manifest）
      case 'paying':   feedLine(m, 'msg pay'); return;
      case 'paid':     feedLine(m, 'msg promote'); return;
    }
  };

  document.getElementById('addBtn').onclick = addBuyer;
  document.getElementById('delBtn').onclick = removeBuyer;
  document.getElementById('rushBtn').onclick = rush;
  document.getElementById('resetBtn').onclick = () => fetch('/reset', { method: 'POST' });

  // 初始：3 個搶票人 + 觀眾
  (async () => {
    await makeSpectator();
    for (let i = 0; i < 3; i++) await addBuyer(); // 依序建立，編號才連續
  })();
</script>
</body>
</html>
`

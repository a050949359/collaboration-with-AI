# ticket-rush — SSE 廣播 + 多人同時搶票 + 候補 + 付款等候室

獨立 Go server（純 stdlib，無外部依賴），與 `cmd/ws-lab`（WebSocket）成對：
這個用 **Server-Sent Events** 示範「廣播」「高併發搶票不超賣」「候補 / 退票 / 付款節流」，
以及讀側的**讀寫分離 + 內容定址分段**擴展性設計。

## 跑法

```bash
go run ./cmd/ticket-rush        # 或 go build -o ticket-rush . && ./ticket-rush
# 開 http://localhost:8099
```

## 玩法

- 每格 = 一個搶票人（最多 12），最下方是只看不搶的觀眾；買家 / 觀眾各有全域唯一編號（跨分頁不撞名）。
- **⚡ 全體同時搶票**：所有人同時打 API，server 串行化 → 5 張不超賣、搶輸的自動進候補（FIFO 號碼牌）。
- **退票**：中票者按退票，3 秒緩衝後釋座、候補隊首遞補；候補者可**離開候補**。
- **付款**：中票後進付款室，**一次只放行 1 人** → 待付款(10s) → 按付款 → 付款中(5s) → 已付款 → 換下一位；
  10 秒未付 = 逾時釋出票源（候補遞補）。等待 / 待付款 / 付款中皆可退票，已付款不可退。
- 開兩個分頁可看到跨分頁同步廣播。

## 一個買家的生命週期

```
搶票 ──售完──▶ 🎫 候補 #N ──(有人退票/逾時)──▶ 遞補中票
  │有位
  ▼
⌛ 等待付款 ──放行──▶ 🕐 待付款(10s) ──按付款──▶ 💳 付款中(5s) ──▶ ✅ 已付款
                        └─ 10s 未按 ──▶ 逾時釋出（候補遞補）
（任何未付款階段都可退票 → 3s 緩衝 → 釋座遞補）
```

## 設計重點

**寫側：單一消費者，天然不超賣**
- `ticketStore` 單一 goroutine 獨佔票數 / 中票者 / 候補 / 付款狀態；併發請求經 channel 串行化 → 5 張不會超賣，無鎖無 data race。
- 候補 FIFO、退票 3s 緩衝、付款室（1-at-a-time / 10s hold / 5s 處理）全在同一顆 250ms ticker 內結算。

**身分：全域唯一發號**
- `/join` 用 atomic 發號，買家 / 觀眾各走一條序列 → 跨分頁名字唯一、接續遞增。

**讀側：讀寫分離 + 合流 + 內容定址分段**
- 寫權威把狀態物化到 `atomic.Pointer[snapshot]`，HTTP 讀端無鎖讀取。
- 不再每次異動整包廣播；SSE 只送**合流後的 manifest（各段內容雜湊）**，每 tick 最多一則 → 壓平「邊爆邊變重」的 O(queue×conn) 廣播尖峰。
- 前端比對 manifest，只 GET 變動的分段（`summary` / `winners` / `queue` 各頁），每段帶 **ETag** → 可 `304` / 可被 CDN 快取。

## API

| 方法 | 路徑 | 用途 |
|------|------|------|
| GET | `/` | 前端頁（內嵌 HTML） |
| GET | `/events` | SSE 串流（連上即收 manifest + 後續廣播） |
| POST | `/join?role=` | 領取唯一編號（buyer / spectator 各自序列） |
| POST | `/buy?name=` | 搶一張票 |
| POST | `/release?name=` | 退票（3s 緩衝後釋座） |
| POST | `/leave?name=` | 離開候補隊伍 |
| POST | `/pay?name=` | 付款（待付款 → 付款中 5s → 已付款） |
| POST | `/reset` | 重置 5 張票 |
| GET | `/state/summary` | 分段：彙總（remaining / queueLen / soldOut），帶 ETag |
| GET | `/state/winners` | 分段：中票者（含付款階段） |
| GET | `/state/queue?page=` | 分段：候補名單分頁 |

## Scaling notes（設計取捨 / 未做與為何）

這是個**單進程教學 lab**：凡「單進程能誠實展示」的就實作（序列化不超賣、付款節流、讀寫分離、合流、分段）；
凡「本質是分散式 / 邊緣」的只在此記錄推理，不在進程內硬模擬（模擬看得到形狀、看不到 payoff）。

**選型分水嶺：先看 SKU 能不能分片**
- **可分片**（多 SKU / fungible 大庫存）→ 分散「寫」：切庫存 key、按 SKU 分片，FCFS 直接水平擴，**連等候室都不必**。
- **不可分片**（單一熱門小庫存、demand ≫ supply — 本 demo 正是）→ 切不動單一熱點 → 只能在前面**削需求**：入場等候室 / 抽籤 / 邊緣。
- demand:supply 比再決定削需求要 FCFS 節流還是抽籤。

**FCFS 的隱藏 race**
- 「一連就搶」與「排隊配位」都是 FCFS，只是把 race 從搶票挪到進場；真正瞬間洪峰下「誰先」是網路運氣，且放行瞬間 lag 會丟票。
- 要徹底免疫 lag / 速度 / 運氣，只有**抽籤**（固定時窗 + 隨機），代價是失去「搶」的能動性。抽籤還順帶讓寫可分散（無序 append + 事後開獎）。

**入場等候室屬「邊緣」，故本 demo 不做**
- payoff 是「分散式接收 / 邊緣吸收連線洪峰」——單進程只能模擬 shard，演不出真正好處。
- 加了它，賽點會從「單點庫存」變「進場接收量」；而接收量**可水平擴（尤其推到邊緣）**，庫存熱點不行。
- 正式站應把它放在**邊緣**（Cloudflare Waiting Room / Queue-it），origin 只吃被放行的涓流。

**其他刻意取捨**
- **delta + Last-Event-ID：選做。** hash 分段是冪等 pull，重連重抓即復原、天生免疫掉包 → 不需要 LEI（除非之後要對熱資料做無損有序 push）。
- **買家 / 連線斷線清理：不做。** 單分頁多買家共用一條 SSE，連線粒度 ≠ 買家粒度，清理不划算；真要做等接進 Laravel（每登入者 = 一 session）才自然。
- **身分 / 防 bot：不在此 demo。** `/join` 是假身分（可無限發號）；正式站應換成登入使用者（Sanctum）+ Turnstile，唯一性才是強制。

## 下一步（productionization）

接進 Laravel（比照 `ws-lab`）：Vue 頁掛 Inertia、URL 進 `routes.ts`、nginx 反代到本 server。
**SSE 串流不可經 PHP-FPM**（長連線會佔住 worker），Laravel 只負責出頁面 / auth / 給連線位址。

接上後，這些「邊緣 / 分散式」缺口才會自然到位：前面掛 **Cloudflare Waiting Room**（入場室）、
共享摘要走 **CDN**、每登入者 = 一 session（解鎖 per-connection 只送你關心的資料、以及斷線後席位復原）。

# 交接清單：Gacha 系統（WebSocket 待決定）

> 更新時間：2026-05-15
> 下一 session 目標：確認 WebSocket 方案後，重新實作即時同步功能

---

## ⚠️ 本 session 重大決定

### Echo + Reverb 已全數移除
Laravel Echo / Reverb 不適合用於遊戲場景（時序控制困難、Queue 架構增加 latency、套件 API 設計易踩坑），本 session 已完整拔除：

**已刪除：**
- `app/Events/Gacha/`（全部 8 個 broadcast event）
- `app/Jobs/Gacha/BroadcastDrawSequence.php`
- `app/Http/Controllers/Gacha/GachaMachineController.php`

**已還原/清除：**
- `composer.json`：移除 `laravel/reverb`
- `package.json`：移除 `@laravel/echo-vue`、`laravel-echo`、`pusher-js`
- `resources/js/app.ts`：移除 `configureEcho` + Pusher 設定
- `resources/js/pages/Gacha.vue`：移除 WebSocket 訂閱邏輯（joinRoom/leaveRoom/onDraw*）
- `resources/js/lib/gacha-api.ts`：清空（machineState 已移除）
- `resources/js/lib/routes.ts`：移除 `api.gacha.machineState`
- `routes/api.php`：移除 machine-state 路由和 test-broadcast 路由
- `routes/channels.php`：移除 gacha channel 授權
- `config/broadcasting.php`：移除 reverb driver
- `.env`：`BROADCAST_CONNECTION=null`

---

## 一、現狀（已完成，保留中）

### DB Migrations
| 資料表 | 欄位 |
|--------|------|
| `gacha_rooms` | code, room_name, status, max_players, min_level, type, owner_id, draws_per_user, can_draw, skip_anim, is_ten_pull |
| `gacha_cards` | name, rarity（enum: common/rare/epic/legendary）, image_url, weight |
| `gacha_players` | room_id, name, avatar, is_host, level, draws_used |
| `gacha_draws` | room_id, player_id, card_id |
| `gacha_messages` | room_id, player_id, message |
| `gacha_room_cards` | pivot（room ↔ card） |

### Models（`app/Models/Gacha/`）✅ 保留
- `GachaRoom`：$fillable + $casts + `cards()` belongsToMany（foreign key: `room_id`）
- `GachaPlayer`：$fillable + `hasDrawsRemaining()` method
- `GachaCard`、`GachaDraw`、`GachaMessage`：基本 relations

### Seeder ✅ 保留
- `database/seeders/GachaTestSeeder`：TEST01 房間 + 5 張卡

### 前端 ✅ 保留（WebSocket 邏輯已移除）
- `resources/js/pages/Gacha.vue`：完整 Matter.js 物理機台
  - 機台：左側 310px、腔體 h-36、按鈕 72×72px、球半徑 7px
  - HOST CONTROL 面板（`isHost = ref(true)` 暫時全開）
  - 抽卡動畫三段：resonance → locked → ejecting（本地 setTimeout 模擬，待 WebSocket 替換）
  - draw 按鈕目前為**本地假抽**，不呼叫 API
- `routes/web.php`：`GET /app/gacha → Inertia('Gacha')`

### rarity 對應關係
| DB enum | 前端 QUALITY_TIERS name | 顏色 |
|---------|------------------------|------|
| common  | common | #a5d1b4 |
| rare    | rare   | #00f2ff |
| epic    | epic   | #a855f7 |
| legendary | legendary | #ffb3b2 |

---

## 二、下一 session 首要任務：確認 WebSocket 方案

### 候選方案
| 方案 | 優點 | 缺點 |
|------|------|------|
| **Socket.io（Node.js server）** | 遊戲設計首選、事件驅動、timing 精確 | 需要額外 Node.js server |
| **Ratchet（PHP WebSocket）** | 純 PHP、不需 Node | 較少維護、文件少 |
| **Polling（setInterval）** | 最簡單、不需 WebSocket | 即時性差、多餘 request |
| **SSE（Server-Sent Events）** | 單向推送、Laravel 原生支援 | 只能伺服器→客戶端 |

### 確認重點
- 多人同步需求：房主改設定要同步給所有人、抽卡動畫要多人同步看
- 動畫 timing 精確度要求（三段：resonance 3s → locked 2s → ejecting）
- 是否接受需要獨立 WebSocket server

---

## 三、WebSocket 選定後待實作

### 後端 API（routes/api.php prefix v1/gacha）
```
POST   /rooms                       建立 User room（auth）
GET    /rooms                       所有房間列表（public）
GET    /rooms/{code}                房間詳情（public）
POST   /rooms/{code}/join           加入房間
DELETE /rooms/{code}/leave          離開
POST   /rooms/{code}/draw           抽卡（權重隨機）→ 觸發動畫序列廣播
POST   /rooms/{code}/chat           聊天
```

### Admin API（prefix v1/gacha/admin，auth + EnsureAdmin）
```
GET/POST/PUT/DELETE  /cards          卡片 CRUD
POST                 /cards/import   CSV/JSON 批次匯入
GET/POST/PUT/DELETE  /rooms          Admin room 管理
GET                  /rooms/{code}/players
DELETE               /players/{id}   踢出玩家
```

### 前端串接
- draw 按鈕 → 呼叫 `/rooms/{code}/draw` API（目前本地假抽）
- `isHost = ref(true)` → 從 WebSocket 連線判斷
- 動畫序列改由 WebSocket 事件驅動（目前 setTimeout 本地模擬）
- `php artisan gacha:cleanup`：定時刪除無人 User room

---

## 四、啟動步驟（目前不需 Reverb）

```bash
# 重建 DB + 測試資料
php artisan migrate:fresh
php artisan db:seed --class=GachaTestSeeder

# 啟動（只需 2 個 terminal）
php artisan serve
npm run dev

# 開 /app/gacha 即可看到物理機台（本地假抽模式）
```

---

## 五、檔案路徑速查

```
app/Models/Gacha/                   GachaRoom, GachaCard, GachaPlayer, GachaDraw, GachaMessage
database/migrations/2026_05_08_*    6 個 gacha migration
database/seeders/GachaTestSeeder    TEST01 測試房間
resources/js/pages/Gacha.vue        前端主頁面（Matter.js 物理機台）
resources/js/lib/gacha-api.ts       API wrapper（目前空，待 WebSocket 方案確定後補）
resources/js/lib/routes.ts          URL 定義（gacha web route 保留）
routes/web.php                      GET /app/gacha
```

---

## 六、重要約定
- **所有 URL 只在 `resources/js/lib/routes.ts` 定義**
- Auth 用 `auth:sanctum` cookie，不需 CSRF
- Admin room 純展示，加入者看得到但不能 join 也不能抽
- rarity 欄位 enum：`common / rare / epic / legendary`（前端 QUALITY_TIERS 同名）

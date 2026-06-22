# 圖片儲存（Image Storage）

把圖片從三種來源收進來，**強制轉成乾淨 webp** 後存本地，依用途分 **public / private** 兩條路線。核心目標：**確保圖片沒有被埋程式碼** + **轉 webp 存本地**。

> [!NOTE]
> 這是「站內自管」的圖片儲存（不依賴 Google Drive 等外部空間）。設計取捨與不採外部空間的原因見本檔結尾「設計決策」。

## 三種來源 → 同一條 pipeline

| 來源 | 入口 | 說明 |
|------|------|------|
| 網頁拖曳上傳 | `ImageIngestService::fromUpload()` | 走 PHP 原生 multipart 上傳（`$_FILES`） |
| AI 生成 binary | `ImageIngestService::fromBinary()` | 直接吃記憶體裡的 bytes（給 Vertex 等生圖端用） |
| 圖片 URL 下載 | `ImageIngestService::fromUrl()` | 伺服器端抓取，含 SSRF 防護 |

三者最後都 funnel 進 private 的 `store()`，跑同一套安全閘：

```
大小閘 → 真實 MIME(finfo,白名單) → 尺寸閘(decompression bomb)
      → 【核心】GD 重新解碼像素再編碼成 webp → 依 visibility 存對應 disk
```

### 安全核心：re-encode，不是「掃描」

不去掃圖片找壞東西，而是 `imagecreatefromstring()` → `imagewebp()` **整張重畫**。
EXIF 藏的 code、檔尾接的 polyglot/PHP、GIF 註解區 payload —— 全部在 re-encode 時**只保留像素、丟棄一切附加資料**。所以就算成品被讀到，最壞情況也只是「圖被看到」，不會變成 code execution。

- MIME 看**內容**（`finfo`）不看副檔名 → `evil.php.jpg` 擋掉。
- 白名單只收 `image/jpeg` / `image/png` / `image/webp`。**GIF / SVG 一律拒收**（GIF 轉 webp 只剩第一幀；SVG 可內嵌 script）。

## Visibility:public vs private

| | **public**（展示素材） | **private**（個人敏感 / NSFW，預設） |
|---|---|---|
| disk | `public`（`storage/app/public`） | `private`（`storage/app/private`） |
| 出圖 | `/storage/...` 直連 URL，**免登入** | `GET /api/images/{id}`，**admin only** 經鑑權 controller 串流 |
| 檔案權限 | `0644` | `0640`（owner rw、同群組 r、others 全擋）/ 目錄 `0750` |
| 用途 | gacha 卡圖等公開美術 | 個人圖庫 |
| 防護 | 不可枚舉的 uuid（但 URL 外流即公開） | 不可枚舉 uuid + 登入牆 |

> [!IMPORTANT]
> **預設是 `private`** —— 不指定 `visibility` 一律走私有鑑權。要公開必須明確帶 `visibility=public`。
> public 的「不可猜」不等於「有存取控制」：URL 一旦外流／被索引，任何人都看得到。**別把敏感內容設成 public。**

> [!NOTE]
> **權限分三層**:
> - `POST /api/images`(admin)— 可指定 public/private。
> - `GET /api/images/{id}`(admin)— private 出圖。
> - `POST /api/images/public`(**任一登入者**)— **強制 public**,不可建 private。
>
> private 相關(建立/讀取)全是 admin only,且沒有「擁有者」概念(所有 admin 看同一批,不是 per-user 私密)。一般登入者只能透過 public 端點貢獻**公開**素材。

---

## API

### `POST /api/images` — 上傳 / 下載並儲存

- **權限**:`auth:sanctum` + **admin only**（`EnsureAdmin`）。
- **限流**:`throttle:30,1`。
- **Body**（`file` 與 `url` **二擇一**，互斥）:

| 欄位 | 型別 | 必填 | 說明 |
|------|------|------|------|
| `file` | multipart file | 與 `url` 二擇一 | 拖曳/選檔上傳 |
| `url` | string(url) | 與 `file` 二擇一 | 由伺服器下載的圖片網址 |
| `visibility` | `public` \| `private` | 否 | 預設 `private` |

**成功 `201`:**

```json
{
  "id": "9f1c2e8a-....-....-....-............",
  "visibility": "private",
  "url": "https://your-domain/api/images/9f1c2e8a-...."
}
```

- `visibility=public` 時 `url` 為 `/storage/...` 直連網址；`private` 時為上方鑑權出圖路由。
- `id` 為不含副檔名的 uuid。成品實際路徑以 **id 前 2 碼分桶**：`images/{前2碼}/{id}.webp`（256 桶,避免單一資料夾檔案過多拖慢 FS）。路徑由 `ImageIngestService::pathFor($id)` 統一推導,id 即唯一 token。

**錯誤:**

| 狀態 | 情境 |
|------|------|
| `401` | 未登入 |
| `403` | 已登入但非 admin |
| `422` | 驗證失敗（`file`/`url` 皆缺或同時給），或被安全閘擋下（MIME 不合、超過大小、無法解碼、GIF、SSRF 命中…）→ `{ "message": "..." }` |

**範例:**

```bash
# 上傳(private 預設)
curl -F file=@photo.png \
     -H "Authorization: Bearer <admin-token>" \
     https://your-domain/api/images

# 上傳成公開素材
curl -F file=@card.png -F visibility=public \
     -H "Authorization: Bearer <admin-token>" \
     https://your-domain/api/images

# 由 URL 下載儲存
curl -X POST -H "Authorization: Bearer <admin-token>" \
     -H "Content-Type: application/json" \
     -d '{"url":"https://example.com/pic.jpg","visibility":"public"}' \
     https://your-domain/api/images
```

### `POST /api/images/public` — 登入者上傳（強制 public）

- **權限**:`auth:sanctum`(**任一登入者**,不需 admin)。
- **限流**:`throttle:30,1`。
- **Body**:同 `POST /api/images`(`file`/`url` 二擇一);**`visibility` 一律忽略並強制 `public`** —— 即使帶 `visibility=private` 也會存成 public。
- 回應同 `store`(`201` → `{ id, visibility:"public", url }`,`url` 為 `/storage/...` 直連)。
- 用途:讓一般使用者貢獻**公開**素材(頭像、卡圖投稿等);private/NSFW 仍只有 admin 能放。

### `GET /api/images/{id}` — 出圖（僅 private）

- **權限**:`auth:sanctum` + **admin only**（訪客 `401`、非 admin `403`）。
- `{id}` 由路由 regex 限成 uuid（`[0-9a-fA-F-]{36}`）→ 擋 path traversal / 枚舉。
- 回應:`200` + `Content-Type: image/webp`、`X-Content-Type-Options: nosniff`、`Cache-Control: private, max-age=86400`；不存在 `404`。
- `<img src>` 走 same-site GET,Sanctum SameSite:Lax cookie 會自動帶上,可正常出圖。
- **public 圖不走這支** —— 直接用 `POST` 回傳的 `/storage/...` URL。

---

## 設定（`config/images.php`）

| 鍵 | 預設 | 說明 |
|----|------|------|
| `disks.public` / `disks.private` | `public` / `private` | 兩條 visibility 對應的 disk |
| `default_visibility` | `private` | 未指定時的 visibility |
| `directory` | `images` | disk 內的子目錄 |
| `max_bytes` | `10MB` | 原始輸入大小上限 |
| `max_megapixels` | `50` | 解析後像素總量上限（擋 decompression bomb） |
| `allowed_mimes` | jpeg/png/webp | 內容層 MIME 白名單 |
| `webp_quality` | `82` | webp 輸出品質 |
| `public_max_files` | `10000` | **public 圖檔數上限**;達上限即拒新上傳（`422`）。`0`/負值 = 不限。只擋 public（private 為 admin only，不受此限） |
| `public_count_driver` | `scan` | 計數方式:`scan`（掃 FS，O(n)，零依賴）或 `redis`（shard hash，取總和近 O(1)） |
| `public_count_redis_key` | `image:public:shard_counts` | `redis` driver 用的 Hash key |
| `download_timeout` / `max_redirects` | `15s` / `3` | URL 下載 |

對應 env:`IMAGE_MAX_BYTES`、`IMAGE_MAX_MEGAPIXELS`、`IMAGE_WEBP_QUALITY`、`IMAGE_DOWNLOAD_TIMEOUT`、`IMAGE_MAX_REDIRECTS`、`IMAGE_PUBLIC_MAX_FILES`、`IMAGE_PUBLIC_COUNT_DRIVER`、`IMAGE_PUBLIC_COUNT_REDIS_KEY`。

## 部署 / 維運注意

- **public 出圖靠 symlink**:需 `php artisan storage:link`（`public/storage` → `storage/app/public`）。
- **上傳大小有多層上限**,最內層才是 `max_bytes`。要讓 10MB 真的可用,前置層都得 ≥ 10MB:
  `nginx client_max_body_size` ≥ `php.ini post_max_size` ≥ `php.ini upload_max_filesize` ≥ Laravel `max:` ≥ `max_bytes`。
- **檔案權限 mode 由 code 鎖死**（`filesystems.php` 的 `private` disk `permissions`:private `0640`/`0750`）;但 **group ownership 是 OS/部署層的事**。0640 的 group-read 只有在「檔案群組 = 共用群組」時才有意義 —— 確認部署使用者已加入 web 程序（www-data）群組,或對 `storage/app/private` 上 setgid（`chmod -R g+s`）讓新檔繼承父目錄群組。
- **暫存檔不會累積**:PHP 原生上傳暫存(`/tmp/phpXXXX`,0600)在 request 結束由 PHP 自動 `unlink`。會持續長大的是**成品 webp**,目前**無自動回收**(孤兒圖需另行清理)。
- **SSRF / DoS（URL 下載）**:`fromUrl()` 只允許 http(s);每一跳解析 **A + AAAA**(IPv4/IPv6)並擋私網/loopback/link-local/ULA/雲端 metadata（`169.254.169.254`、`::1`、`fc00::/7` 等）;把驗證過的 IP 用 **`CURLOPT_RESOLVE` pin 給 curl**,防 DNS rebinding(TOCTOU);**串流邊讀邊累加**、超過 `max_bytes` 立即中斷(防 OOM);protocol-relative(`//host`)redirect 正確繼承 scheme 後重驗;限 redirect 次數。
- **Production 建議 `IMAGE_PUBLIC_COUNT_DRIVER=redis`**:`scan` 每次上傳都 `allFiles()` 遞迴掃目錄,圖一多會有 I/O 負擔;redis 走 shard hash 取總和,常數成本。
- **public 檔數計數**（`PublicImageCounter`）:`scan` 直接掃 FS;`redis` 用一個 Hash（field = shard 2 碼、value = 該桶檔數),`hincrby` 原子加、`hgetall` 加總取 total,冷啟動自動從 FS seed（self-healing）。屬軟上限近似值 —— 若 public 圖被 app 外刪除會與實際脫節,需要時可重掃覆寫 hash 校正。

## 待補（TODO）

> [!NOTE]
> 以下尚未實作,待之後補:

- **`redis` driver 的計數漂移校正**。redis 模式下 counter 只增不減,且 self-healing 只在 hash 完全不存在時觸發;若 public 圖被 **app 外刪除**,hash 會高估、可能誤擋新上傳(不影響資料/出圖,純軟上限算不準)。`scan` 模式無此問題。待補其一:
  - `PublicImageCounter::removed($id)`(`hincrby -1`),供未來「刪圖功能」呼叫;
  - 一支 `php artisan image:reconcile-public-count`(重掃 FS 覆寫 hash,或直接 `Redis::del(key)` 讓下次讀取冷啟動 re-seed),可掛排程定期校正。
- **孤兒成品圖回收**。成品 webp 無自動回收;沒被引用的圖會一直留著(尤其卡片刪除時未連帶刪圖)。待補清理機制。

## 關鍵檔案

| 檔案 | 角色 |
|------|------|
| `app/Services/Image/ImageIngestService.php` | 核心 pipeline（三入口 + 安全閘 + re-encode + SSRF） |
| `app/Services/Image/ImageRejectedException.php` | 被擋下時拋出 → controller 轉 `422` |
| `app/Services/Image/PublicImageCounter.php` | public 檔數計數（`scan` / `redis` shard hash） |
| `app/Http/Controllers/ImageController.php` | `store` / `show` |
| `app/Http/Requests/StoreImageRequest.php` | `file`/`url` 互斥 + `visibility` 驗證 |
| `app/Enums/ImageVisibility.php` | `public` / `private` |
| `config/images.php` | 限制值與 disk 對應 |
| `routes/api.php` | `POST /images`(admin)、`GET /images/{id}`(admin)、`POST /images/public`(登入) |
| `tests/Feature/ImageIngestTest.php` | 安全閘 / 權限 / SSRF 測試 |

## 設計決策

- **為何不放 Google Drive 等外部空間**:曾考慮但放棄。為躲內容掃描而編碼 = 規避平台濫用偵測,且封鎖風險不會消失、還可能連坐整個帳號。合法內容若平台 ToS 不收,正解是「換允許的儲存」或自架,而非騙過掃描。最終決定**直接存本地**。
- **為何強制 re-encode 而非掃描**:掃描永遠追不完各種 polyglot/隱寫;重畫像素是「結構性」消毒,一次解決絕大多數「圖片埋 code」攻擊。
- **為何拆 public/private**:展示美術(gacha 卡圖)要公開直連,個人/NSFW 要私有鑑權 —— 兩種存取模型本質不同,硬塞一條會互相妥協。

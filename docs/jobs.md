# 背景 Job / Queue

非同步工作(生成文章、匯出 PDF、城市搜尋、故事推進)丟到 queue,由 worker 背景跑,避免阻塞 HTTP 請求。

## 運作機制

```
Controller / 排程 ──dispatch──▶ queue(redis)──▶ queue:work worker ──▶ Job::handle()
```
- **連線**:`QUEUE_CONNECTION`(`config/queue.php` 預設 `database`,本專案 `.env` 設 **`redis`**)。
- **跑 worker**:`php artisan queue:work`(prod 用 supervisor/systemd 常駐)。
- **排程**:`php artisan schedule:run`(每分鐘 cron)驅動 `routes/console.php` 的 `Schedule::*`。

## Job 清單

| Job | 用途 | 誰 dispatch | 特性 |
|-----|------|------------|------|
| **GenerateArticleContentJob** | 生成文章內文(走 `GeneratesArticleContent` = Vertex) | `ArticleGenerationController`、`LineArticleController` | `ShouldBeUnique`(uniqueId=article id,防重複生成);完成後 dispatch LINE webhook |
| **GenerateArticleImageJob** | 文章配圖(Vertex Imagen) | `ArticleGenerationController` | `ShouldBeUnique` |
| **DispatchLineArticleReadyWebhookJob** | 文章好了通知 LINE webhook | 由 `GenerateArticleContentJob` 完成時 dispatch | — |
| **GenerateExportJob** | 行程匯出 PDF(`TourExportService`) | `ExportController` | 帶 service class + params + taskId |
| **SearchCityJob** | 城市搜尋(航空資料) | `CitySearchController` | 前端輪詢 job 狀態直到完成(Countries 頁) |
| **Story/StoryOrchestrateJob** | **編排**:組 `Bus::chain`(多個 SegmentJob + 末尾 StateJob,間隔 delay);`catch` 失敗 → session 轉 `paused` | `StorySessionController`(玩家回合)+ **排程 story-clock** | 故事推進的總入口 |
| **Story/StorySegmentJob** | 單一角色段落生成(`LlmStoryService`,用途 `story`) | 由 Orchestrate 放進 chain | 鏈中依序執行 |
| **Story/StoryStateJob** | 世界狀態更新(`LlmStoryService`,用途 `story_state`) | 由 Orchestrate 放在 chain 末尾 | — |

## 排程(`routes/console.php`)

| 排程 | 間隔 | 做什麼 |
|------|------|--------|
| `bookings:release-expired`(command) | 每分鐘 | 釋放逾時未付款訂單 |
| `story-clock`(closure) | **每小時** | 掃 `active` 且 `next_advance_at <= now` 的故事 session,**先認領** `next_advance_at`(防重複)→ dispatch `StoryOrchestrateJob`;`withoutOverlapping` |

## 失敗與重試

重試/逾時設在 **worker 啟動指令**(prod 以 systemd 常駐,範本見 [`deploy/laravel-queue.service`](../deploy/laravel-queue.service)):
```bash
php artisan queue:work redis --tries=3 --timeout=180
```
- `--tries=3`:失敗重試 3 次(per-job 未設 `$tries` 就吃這個)。
- `--timeout=180`:單一 job 跑超過 180s 被砍。

### ⚠️ `retry_after` 必須 > worker `--timeout`
redis 連線的 `retry_after`(`REDIS_QUEUE_RETRY_AFTER`,**預設 90**)= 「job 多久沒結束就視為失敗、重新派發」。它**必須大於 `--timeout`**,否則:

> job 跑到 `retry_after`(90s)時被**重新派給另一個 worker(重複執行)**,而原 job 還跑到 180s 才被 timeout 砍。

→ 90~180s 的慢 job(AI 文章、故事接龍的 LLM/Vertex)會**被執行兩次**。
**修正**:`REDIS_QUEUE_RETRY_AFTER=210`(> `--timeout` 180,留緩衝;相等也不行,會有邊界 race)。

### per-job 覆蓋(可選)
worker 已全域 `--tries`/`--timeout`;只有某 job 要**不同值**才在 class 內加:
- `public int $tries` / `public int $timeout`(例:不該重試的設 `tries=1`;快 job 設短 timeout)。
- `ShouldBeUnique` 的 job 若會重試 → 設 `$uniqueFor` 讓 unique 鎖涵蓋重試期(否則重試被自己鎖)。
- **Story chain**:`Bus::chain(...)->catch()` 在任一環節失敗時把 session 轉 `paused`。

## 怎麼加 / 用一個 Job

```php
// 1. 建 Job:implements ShouldQueue,handle() 可型別注入服務(容器解析)
class FooJob implements ShouldQueue {
    use Queueable;
    public function __construct(private int $id) {}
    public int $tries = 3;          // ← 記得設重試
    public int $timeout = 120;
    public function handle(SomeService $svc): void { /* ... */ }
}

// 2. dispatch(從 controller / 其他 job)
FooJob::dispatch($model->id);
FooJob::dispatch($id)->delay(now()->addSeconds(10));   // 延遲
Bus::chain([new A(), new B()])->dispatch();             // 依序鏈

// 3. 同一資源防重複 → implements ShouldBeUnique + uniqueId()
```

## 相關
- 文章 Job 走 Vertex(見 [ai-llm.md](ai-llm.md));Story Job 走 LLM 用途 `story`/`story_state`。
- Story 排程/狀態機細節:[story.md](story.md)(待寫)。

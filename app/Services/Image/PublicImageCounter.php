<?php

namespace App\Services\Image;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/**
 * public 圖檔數計數器。
 *
 * Redis 結構:一個 Hash(field = shard 2 碼、value = 該桶檔數),
 * `hincrby` 原子加、`hgetall` 後加總取 total —— 每桶一個值、要算時取總和。
 *
 * driver:
 *  - `scan`(預設):直接掃 FS 計數(O(n)),零依賴、測試友善。
 *  - `redis`:用上述 Hash;冷啟動(hash 不存在)自動從 FS seed,self-healing。
 *
 * 註:這是「軟上限」用的近似計數。若 public 圖被 app 外刪除會與實際脫節,
 * 需要時可另寫 reconcile 指令重掃覆寫 hash。
 */
class PublicImageCounter
{
    /** 目前 public 圖總數 */
    public function total(): int
    {
        if ($this->driver() !== 'redis') {
            return $this->scanTotal();
        }

        $counts = Redis::hgetall($this->key());

        if (! is_array($counts) || $counts === []) {
            return $this->seedFromScan(); // 冷啟動:從 FS 補
        }

        return (int) array_sum(array_map('intval', $counts));
    }

    /** 成功寫入一張 public 圖後呼叫,維護對應 shard 計數 */
    public function added(string $id): void
    {
        if ($this->driver() !== 'redis') {
            return; // scan 模式不需維護
        }

        Redis::hincrby($this->key(), substr($id, 0, 2), 1);
    }

    private function scanTotal(): int
    {
        return count($this->disk()->allFiles($this->dir()));
    }

    /** 掃 FS 逐 shard 計數寫進 hash,回傳總數 */
    private function seedFromScan(): int
    {
        $perShard = [];
        foreach ($this->disk()->allFiles($this->dir()) as $file) {
            $shard = $this->shardFromPath($file);
            if ($shard === '') {
                continue;
            }
            $perShard[$shard] = ($perShard[$shard] ?? 0) + 1;
        }

        if ($perShard !== []) {
            Redis::hmset($this->key(), $perShard);
        }

        return (int) array_sum($perShard);
    }

    /** images/ab/uuid.webp → ab */
    private function shardFromPath(string $path): string
    {
        $parts = explode('/', $path);

        return $parts[count($parts) - 2] ?? '';
    }

    private function driver(): string
    {
        return (string) config('images.public_count_driver', 'scan');
    }

    private function key(): string
    {
        return (string) config('images.public_count_redis_key', 'image:public:shard_counts');
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('images.disks.public'));
    }

    private function dir(): string
    {
        return trim((string) config('images.directory'), '/');
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Territory 世界層摘要的快取鍵與失效。
 *
 * 獨立成一個 class 而不是掛在 TerritoryBrowseController 上：失效的呼叫端是**寫入
 * 流程**（queue job、MCP 的寫入工具），讓它們去 use 一個 controller 會把 HTTP 層
 * 綁進資料寫入層，相依方向也反了。
 *
 * 讀寫本身留在 controller 裡——那段「讀 → build → 寫」的順序有它自己的理由
 * （見 TerritoryBrowseController::countries()），包成 helper 反而看不見。
 */
final class TerritoryCache
{
    public const COUNTRIES_KEY = 'territory:countries';

    /** 行政區資料幾乎不變，放長一點；寫入端會主動失效，不必靠 TTL 過期。 */
    public const COUNTRIES_TTL = 86400;

    /**
     * 資料寫入後呼叫，讓世界層摘要下次重新產生。
     *
     * 吞掉例外：快取層掛掉時，失效失敗頂多是資料晚一點更新，不該讓寫入流程
     * （匯入、refresh_observations）因此整個失敗。
     */
    public static function forgetCountries(): void
    {
        try {
            Cache::forget(self::COUNTRIES_KEY);
        } catch (\Throwable) {
            // 見上：失效失敗不影響寫入本身
        }
    }
}

<?php

namespace App\Enums;

/**
 * 知識圖譜 observation 的類型。
 *
 * - desc 為預設純文字描述（現有 REST / MCP 讀取路徑只取此型）。
 * - 其餘為結構化 typed 資料，內容格式由各 type 自行約定（如 geo 存 "lat,lng"）。
 * - 顯示文字（label）走前端 i18n，不放這裡（見 CLAUDE.md enum 前後端分工）。
 * - 不含 visibility：讀取權限由各 type 各自的 route middleware 控管。
 */
enum ObservationType: string
{
    case Desc = 'desc'; // 純文字描述
    case Geo = 'geo';   // 經緯度座標 "lat,lng"（globe 視圖用）

    /**
     * 單一 entity 同一 type 允許的最大筆數；null = 無限制。
     */
    public function maxCount(): ?int
    {
        return match ($this) {
            self::Desc => null, // 描述可多條
            self::Geo => 1,     // 一個節點僅一個座標
        };
    }
}

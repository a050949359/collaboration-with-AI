<?php

namespace App\Enums\Rag;

/**
 * Drive 檔相對某知識庫的狀態（列檔/選庫時標示）。
 * 與 DocumentStatus 不同：這是「Drive 檔 vs KB」的關係，非草稿生命週期。
 */
enum DriveFileStatus: string
{
    case New = 'new';          // 此庫尚無對應 document
    case InKb = 'in_kb';       // 已在庫中，且 Drive 未更新
    case Changed = 'changed';  // 已在庫中，但 Drive modifiedTime 已變（來源更新）

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

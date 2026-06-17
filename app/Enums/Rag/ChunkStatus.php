<?php

namespace App\Enums\Rag;

enum ChunkStatus: string
{
    case Draft = 'draft';          // 草稿塊,可能尚未 embed 或內容已變
    case Committed = 'committed';   // 已 embed 且已 upsert 進向量庫

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

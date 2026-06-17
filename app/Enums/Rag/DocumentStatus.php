<?php

namespace App\Enums\Rag;

enum DocumentStatus: string
{
    case Draft = 'draft';         // 已切塊、編輯中,尚未落向量庫
    case Committed = 'committed';  // 已 embed + upsert 進 vecgen
    case Dirty = 'dirty';          // 已 commit 過,但草稿又被改、與向量庫不同步

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

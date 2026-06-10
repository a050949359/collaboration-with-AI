<?php

namespace App\Models\Mcp;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $entity_id
 * @property string $content
 * @property string $type
 */
class McpObservation extends Model
{
    /** 預設觀察類型：純文字描述，對應現有 REST / MCP 讀取路徑。 */
    public const TYPE_DEFAULT = 'desc';

    protected $fillable = ['entity_id', 'content', 'type'];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(McpEntity::class, 'entity_id');
    }

    /** 只取預設（desc）觀察——現有 REST 與 MCP 工具一律走此範圍。 */
    public function scopeOfDefaultType(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_DEFAULT);
    }
}

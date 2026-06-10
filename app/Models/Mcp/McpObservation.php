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

    /** 新實例即帶預設 type，確保 create 後記憶體與 DB 一致（回傳 JSON 含 type）。 */
    protected $attributes = ['type' => self::TYPE_DEFAULT];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(McpEntity::class, 'entity_id');
    }

    /** 只取預設（desc）觀察——現有 REST 與 MCP 工具一律走此範圍。 */
    public function scopeOfDefaultType(Builder $query): Builder
    {
        // 加表前綴：mcp_entities 也有 type 欄，關聯/子查詢時避免欄位模糊
        return $query->where($this->qualifyColumn('type'), self::TYPE_DEFAULT);
    }
}

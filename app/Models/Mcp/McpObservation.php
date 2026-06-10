<?php

namespace App\Models\Mcp;

use App\Enums\ObservationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $entity_id
 * @property string $content
 * @property ObservationType $type
 */
class McpObservation extends Model
{
    /** 預設觀察類型：純文字描述，對應現有 REST / MCP 讀取路徑。 */
    public const TYPE_DEFAULT = ObservationType::Desc->value;

    protected $fillable = ['entity_id', 'content', 'type'];

    /** 新實例即帶預設 type，確保 create 後記憶體與 DB 一致（回傳 JSON 含 type）。 */
    protected $attributes = ['type' => self::TYPE_DEFAULT];

    protected function casts(): array
    {
        return ['type' => ObservationType::class];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(McpEntity::class, 'entity_id');
    }

    /** 依 type 篩選。加表前綴：mcp_entities 也有 type 欄，關聯/子查詢時避免欄位模糊。 */
    public function scopeOfType(Builder $query, ObservationType $type): Builder
    {
        return $query->where($this->qualifyColumn('type'), $type->value);
    }

    /** 只取預設（desc）觀察——現有 REST 與 MCP 工具一律走此範圍。 */
    public function scopeOfDefaultType(Builder $query): Builder
    {
        return $query->ofType(ObservationType::Desc);
    }

    /** 只取非預設（desc 以外，即 geo… 結構化）觀察。與 ofDefaultType 對稱。 */
    public function scopeExceptDefaultType(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('type'), '!=', ObservationType::Desc->value);
    }
}

<?php

namespace App\Models\Mcp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $entity_id
 * @property string $content
 */
class McpObservation extends Model
{
    protected $fillable = ['entity_id', 'content'];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(McpEntity::class, 'entity_id');
    }
}

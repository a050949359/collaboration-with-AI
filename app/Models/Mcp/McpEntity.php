<?php

namespace App\Models\Mcp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $type
 */
class McpEntity extends Model
{
    protected $fillable = ['name', 'type'];

    public function observations(): HasMany
    {
        return $this->hasMany(McpObservation::class, 'entity_id');
    }

    public function relationsFrom(): HasMany
    {
        return $this->hasMany(McpRelation::class, 'from_entity_id');
    }

    public function relationsTo(): HasMany
    {
        return $this->hasMany(McpRelation::class, 'to_entity_id');
    }
}

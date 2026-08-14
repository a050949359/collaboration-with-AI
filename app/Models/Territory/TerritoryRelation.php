<?php

namespace App\Models\Territory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $from_entity_id
 * @property int $to_entity_id
 * @property string $relation_type
 */
class TerritoryRelation extends Model
{
    protected $fillable = ['from_entity_id', 'to_entity_id', 'relation_type'];

    /** @return BelongsTo<TerritoryEntity, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(TerritoryEntity::class, 'from_entity_id');
    }

    /** @return BelongsTo<TerritoryEntity, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(TerritoryEntity::class, 'to_entity_id');
    }
}

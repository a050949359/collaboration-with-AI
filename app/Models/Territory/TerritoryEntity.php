<?php

namespace App\Models\Territory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $type
 */
class TerritoryEntity extends Model
{
    protected $fillable = ['name', 'type'];

    /** @return HasMany<TerritoryObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(TerritoryObservation::class, 'entity_id');
    }

    /** @return HasMany<TerritoryRelation, $this> */
    public function relationsFrom(): HasMany
    {
        return $this->hasMany(TerritoryRelation::class, 'from_entity_id');
    }

    /** @return HasMany<TerritoryRelation, $this> */
    public function relationsTo(): HasMany
    {
        return $this->hasMany(TerritoryRelation::class, 'to_entity_id');
    }
}

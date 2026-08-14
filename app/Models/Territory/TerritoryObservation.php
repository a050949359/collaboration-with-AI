<?php

namespace App\Models\Territory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $entity_id
 * @property string $content
 * @property string $type
 */
class TerritoryObservation extends Model
{
    public const TYPE_DEFAULT = 'desc';

    protected $fillable = ['entity_id', 'content', 'type'];

    protected $attributes = ['type' => self::TYPE_DEFAULT];

    /** @return BelongsTo<TerritoryEntity, $this> */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(TerritoryEntity::class, 'entity_id');
    }
}

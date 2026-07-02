<?php

namespace App\Models\Story;

use App\Enums\StoryCharacterType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoryCharacter extends Model
{
    protected $fillable = [
        'session_id',
        'name',
        'persona',
        'type',
        'model_config',
        'turn_order',
        'is_narrator',
        'status',
    ];

    protected $casts = [
        'model_config' => 'array',
        'is_narrator' => 'boolean',
        'type' => StoryCharacterType::class,
    ];

    /** @return BelongsTo<StorySession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(StorySession::class, 'session_id');
    }

    /** @return HasMany<StorySegment, $this> */
    public function segments(): HasMany
    {
        return $this->hasMany(StorySegment::class, 'character_id');
    }

    /** @return HasMany<StoryItem, $this> */
    public function heldItems(): HasMany
    {
        return $this->hasMany(StoryItem::class, 'holder_character_id');
    }

    public function isSkippable(): bool
    {
        return $this->status !== 'active';
    }
}

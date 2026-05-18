<?php

namespace App\Models\Story;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorySession extends Model
{
    protected $fillable = [
        'title',
        'setting',
        'world_state',
        'current_character_id',
        'advance_interval_minutes',
        'rounds_without_progress',
        'status',
        'content_rating',
        'next_advance_at',
    ];

    protected $casts = [
        'setting'          => 'array',
        'next_advance_at'  => 'datetime',
    ];

    public function characters(): HasMany
    {
        return $this->hasMany(StoryCharacter::class, 'session_id')->orderBy('turn_order');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(StorySegment::class, 'session_id')->orderBy('turn_number');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StoryItem::class, 'session_id');
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(StoryScene::class, 'session_id');
    }

    public function currentCharacter(): BelongsTo
    {
        return $this->belongsTo(StoryCharacter::class, 'current_character_id');
    }

    public function isStalled(int $threshold = 3): bool
    {
        return $this->rounds_without_progress >= $threshold;
    }
}

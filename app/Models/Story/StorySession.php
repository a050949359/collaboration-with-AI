<?php

namespace App\Models\Story;

use App\Enums\StoryContentRating;
use App\Enums\StorySessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int              $id
 * @property string           $title
 * @property array            $setting
 * @property string           $world_state
 * @property int|null         $current_character_id
 * @property int              $advance_interval_minutes
 * @property int              $rounds_per_advance
 * @property int              $rounds_without_progress
 * @property StorySessionStatus $status
 * @property StoryContentRating $content_rating
 * @property \Carbon\Carbon|null $next_advance_at
 * @property bool             $needs_event
 * @property string|null      $pending_scene_location
 * @property int|null         $state_last_turn
 * @property bool             $needs_complete
 * @property int|null         $complete_deadline_turn
 */
class StorySession extends Model
{
    protected $fillable = [
        'title',
        'setting',
        'world_state',
        'current_character_id',
        'advance_interval_minutes',
        'rounds_per_advance',
        'rounds_without_progress',
        'status',
        'content_rating',
        'next_advance_at',
        'needs_event',
        'pending_scene_location',
        'state_last_turn',
        'needs_complete',
        'complete_deadline_turn',
    ];

    protected $casts = [
        'setting'        => 'array',
        'next_advance_at'  => 'datetime',
        'needs_event'      => 'boolean',
        'needs_complete'   => 'boolean',
        'content_rating' => StoryContentRating::class,
        'status'         => StorySessionStatus::class,
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

    /**
     * Find and set the next active character in turn order.
     * Skips unconscious / captured / dead characters automatically.
     * Returns the new current character, or null if none are active.
     */
    public function advanceToNextCharacter(): ?StoryCharacter
    {
        $activeCharacters = $this->characters()
            ->where('status', StorySessionStatus::Active)
            ->orderBy('turn_order')
            ->get();

        if ($activeCharacters->isEmpty()) {
            return null;
        }

        $currentOrder = $this->currentCharacter?->turn_order ?? -1;

        $next = $activeCharacters->firstWhere('turn_order', '>', $currentOrder)
            ?? $activeCharacters->first();

        $this->update(['current_character_id' => $next->id]);

        return $next;
    }
}

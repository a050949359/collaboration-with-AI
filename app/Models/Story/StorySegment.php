<?php

namespace App\Models\Story;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorySegment extends Model
{
    protected $fillable = [
        'session_id',
        'character_id',
        'content',
        'turn_number',
        'is_player_written',
        'is_event',
    ];

    protected $casts = [
        'is_player_written' => 'boolean',
        'is_event' => 'boolean',
    ];

    /** @return BelongsTo<StorySession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(StorySession::class, 'session_id');
    }

    /** @return BelongsTo<StoryCharacter, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(StoryCharacter::class, 'character_id');
    }
}

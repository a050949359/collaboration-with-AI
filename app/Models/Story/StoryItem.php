<?php

namespace App\Models\Story;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryItem extends Model
{
    protected $fillable = [
        'session_id',
        'name',
        'description',
        'holder_character_id',
        'location_hint',
        'is_preset',
    ];

    protected $casts = [
        'is_preset' => 'boolean',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StorySession::class, 'session_id');
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(StoryCharacter::class, 'holder_character_id');
    }
}

<?php

namespace App\Models\Story;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryScene extends Model
{
    protected $fillable = [
        'session_id',
        'location_name',
        'description',
        'first_visited_at',
    ];

    protected $casts = [
        'first_visited_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StorySession::class, 'session_id');
    }
}

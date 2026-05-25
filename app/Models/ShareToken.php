<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShareToken extends Model
{
    protected $fillable = ['token', 'scope', 'max_uses', 'uses_count', 'note', 'expires_at', 'line_user_id', 'created_by'];

    protected $casts = [
        'expires_at' => 'datetime',
        'max_uses'   => 'integer',
        'uses_count' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function findByRaw(string $raw): ?self
    {
        return self::where('token', hash('sha256', $raw))->first();
    }

    public function isValid(): bool
    {
        if ($this->expires_at?->isPast()) {
            return false;
        }
        if ($this->max_uses !== null && $this->uses_count >= $this->max_uses) {
            return false;
        }
        return true;
    }

    public function incrementUses(): void
    {
        $this->increment('uses_count');
    }
}

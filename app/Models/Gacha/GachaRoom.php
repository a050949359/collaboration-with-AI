<?php

namespace App\Models\Gacha;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GachaRoom extends Model
{
    protected $fillable = [
        'code', 'room_name', 'status', 'max_players', 'min_level',
        'type', 'owner_id', 'deck_id',
    ];

    public function players()
    {
        return $this->hasMany(GachaPlayer::class, 'room_id');
    }

    /** @return BelongsTo<GachaDeck, $this> */
    public function deck(): BelongsTo
    {
        return $this->belongsTo(GachaDeck::class, 'deck_id');
    }

    public function draws()
    {
        return $this->hasMany(GachaDraw::class, 'room_id');
    }

    public function messages()
    {
        return $this->hasMany(GachaMessage::class, 'room_id');
    }
}

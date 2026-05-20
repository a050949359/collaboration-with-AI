<?php

namespace App\Models\Gacha;

use Illuminate\Database\Eloquent\Model;

class GachaDraw extends Model
{
    protected $fillable = ['room_id', 'player_id', 'card_id', 'result'];

    protected $casts = ['result' => 'array'];

    public function room()
    {
        return $this->belongsTo(GachaRoom::class, 'room_id');
    }

    public function player()
    {
        return $this->belongsTo(GachaPlayer::class, 'player_id');
    }

    public function card()
    {
        return $this->belongsTo(GachaCard::class, 'card_id');
    }
}

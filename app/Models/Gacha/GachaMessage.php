<?php

namespace App\Models\Gacha;

use Illuminate\Database\Eloquent\Model;

class GachaMessage extends Model
{
    protected $fillable = ['room_id', 'player_id', 'message'];

    public function room()
    {
        return $this->belongsTo(GachaRoom::class, 'room_id');
    }

    public function player()
    {
        return $this->belongsTo(GachaPlayer::class, 'player_id');
    }
}

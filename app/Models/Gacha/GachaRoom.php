<?php

namespace App\Models\Gacha;

use Illuminate\Database\Eloquent\Model;

class GachaRoom extends Model
{
    protected $fillable = ['code', 'room_name', 'status', 'max_players', 'min_level'];

    public function players()
    {
        return $this->hasMany(GachaPlayer::class, 'room_id');
    }

    public function cards()
    {
        return $this->belongsToMany(GachaCard::class, 'gacha_room_cards');
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

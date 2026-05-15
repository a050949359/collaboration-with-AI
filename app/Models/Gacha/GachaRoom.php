<?php

namespace App\Models\Gacha;

use Illuminate\Database\Eloquent\Model;

class GachaRoom extends Model
{
    protected $fillable = [
        'code', 'room_name', 'status', 'max_players', 'min_level',
        'type', 'owner_id', 'draws_per_user', 'can_draw', 'skip_anim', 'is_ten_pull',
    ];

    protected $casts = [
        'can_draw'     => 'boolean',
        'skip_anim'    => 'boolean',
        'is_ten_pull'  => 'boolean',
    ];

    public function players()
    {
        return $this->hasMany(GachaPlayer::class, 'room_id');
    }

    public function cards()
    {
        return $this->belongsToMany(GachaCard::class, 'gacha_room_cards', 'room_id', 'card_id');
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

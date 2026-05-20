<?php

namespace App\Models\Gacha;

use Illuminate\Database\Eloquent\Model;

class GachaPlayer extends Model
{
    protected $fillable = ['room_id', 'name', 'is_host', 'level', 'draws_used'];

    public function hasDrawsRemaining(int $limit): bool
    {
        return $limit === 0 || $this->draws_used < $limit;
    }

    public function room()
    {
        return $this->belongsTo(GachaRoom::class, 'room_id');
    }

    public function draws()
    {
        return $this->hasMany(GachaDraw::class, 'player_id');
    }

    public function messages()
    {
        return $this->hasMany(GachaMessage::class, 'player_id');
    }
}

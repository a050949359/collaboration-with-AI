<?php

namespace App\Models\Gacha;

use Illuminate\Database\Eloquent\Model;

class GachaCard extends Model
{
    protected $fillable = ['name', 'rarity', 'image_url', 'weight'];

    public function rooms()
    {
        return $this->belongsToMany(GachaRoom::class, 'gacha_room_cards');
    }
}

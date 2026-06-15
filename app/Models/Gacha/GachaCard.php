<?php

namespace App\Models\Gacha;

use App\Enums\GachaRarity;
use Illuminate\Database\Eloquent\Model;

class GachaCard extends Model
{
    protected $fillable = ['name', 'rarity', 'image_url', 'weight'];

    protected $casts = [
        'rarity' => GachaRarity::class,
    ];
}

<?php

namespace App\Models\Gacha;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GachaDeck extends Model
{
    protected $fillable = ['name'];

    /** @return BelongsToMany<GachaCard, $this> */
    public function cards()
    {
        return $this->belongsToMany(GachaCard::class, 'gacha_deck_cards', 'deck_id', 'card_id');
    }

    public function rooms()
    {
        return $this->hasMany(GachaRoom::class, 'deck_id');
    }
}

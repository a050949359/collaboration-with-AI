<?php

namespace App\Models\Aviation;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    protected $fillable = [
        'wikidata_id',
        'name_en',
        'name_zh_tw',
        'name_zh',
        'country_code',
        'latitude',
        'longitude',
        'population',
    ];
}

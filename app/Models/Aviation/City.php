<?php

namespace App\Models\Aviation;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    protected $fillable = [
        'wikidata_id',
        'name_en',
        'name_zh_tw',
        'country_code',
        'latitude',
        'longitude',
        'population',
        'timezone',
        'elevation',
        'area',
        'description',
        'image_url',
        'wikipedia_url',
        'phone_code',
        'postal_code',
        'submitted_by',
    ];

    public function country(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }
}

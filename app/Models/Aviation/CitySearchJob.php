<?php

namespace App\Models\Aviation;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CitySearchJob extends Model
{
    protected $fillable = [
        'city_name',
        'wikidata_qid',
        'country_code',
        'status',
        'city_id',
        'error',
        'submitted_by',
    ];

    public function city(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function submitter(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}

<?php

namespace App\Models\Aviation;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $fillable = [
        'code',
        'alpha3',
        'numeric',
        'name_en',
        'name_zh_tw',
        'name_zh',
        'capital',
        'phone_code',
        'parent_code',
        'notes',
    ];
}

<?php

namespace App\Models\Story;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int         $id
 * @property string      $name
 * @property string      $persona
 * @property string|null $secret
 * @property string|null $background
 * @property array|null  $appearance   { age, hair, eyes, build, features }
 * @property string|null $outfit
 * @property string|null $image_prompt
 */
class Character extends Model
{
    protected $fillable = [
        'name',
        'persona',
        'secret',
        'background',
        'appearance',
        'outfit',
        'image_prompt',
    ];

    protected $casts = [
        'appearance' => 'array',
    ];
}

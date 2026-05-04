<?php

namespace App\Models\Travel;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name', 'email', 'phone', 'country', 'language'])]
class Guide extends Model
{
    use HasFactory;
    public function tours()
    {
        return $this->belongsToMany(Tour::class, 'tour_guides');
    }
}
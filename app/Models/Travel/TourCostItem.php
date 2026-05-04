<?php

namespace App\Models\Travel;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['tour_id', 'type', 'description', 'amount', 'per_person'])]
class TourCostItem extends Model
{
    use HasFactory;
    protected $casts = ['per_person' => 'boolean'];

    public function tour()
    {
        return $this->belongsTo(Tour::class);
    }
}
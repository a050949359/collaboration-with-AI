<?php

namespace App\Models\Travel;

use App\Enums\CabinClass;
use App\Models\Airports\Airports;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'tour_id', 'flight_number', 'cabin_class',
    'origin_airport_id', 'destination_airport_id',
    'departure_time', 'arrival_time', 'cost_price', 'remarks',
])]
class TourFlight extends Model
{
    use HasFactory;
    protected $casts = [
        'cabin_class'    => CabinClass::class,
        'departure_time' => 'datetime',
        'arrival_time'   => 'datetime',
    ];

    public function tour()
    {
        return $this->belongsTo(Tour::class);
    }

    public function originAirport()
    {
        return $this->belongsTo(Airports::class, 'origin_airport_id');
    }

    public function destinationAirport()
    {
        return $this->belongsTo(Airports::class, 'destination_airport_id');
    }
}

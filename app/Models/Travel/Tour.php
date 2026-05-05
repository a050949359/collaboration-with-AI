<?php

namespace App\Models\Travel;

use App\Enums\TourType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'code', 'name', 'type', 'duration',
    'departure_date', 'return_date',
    'selling_price', 'target_profit',
    'min_pax', 'max_pax',
    'tour_leader_id', 'remarks',
])]

/**
 * @property Carbon $departure_date
 * @property Carbon $return_date
 */
class Tour extends Model
{
    use HasFactory;
    protected $casts = [
        'type'           => TourType::class,
        'departure_date' => 'date',
        'return_date'    => 'date',
    ];

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function flights()
    {
        return $this->hasMany(TourFlight::class);
    }

    public function hotels()
    {
        return $this->hasMany(TourHotel::class);
    }

    public function costItems()
    {
        return $this->hasMany(TourCostItem::class);
    }

    public function tourLeader()
    {
        return $this->belongsTo(TourLeader::class);
    }

    public function guides()
    {
        return $this->belongsToMany(Guide::class, 'tour_guides');
    }
}
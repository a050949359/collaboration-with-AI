<?php

namespace App\Models\Travel;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'booking_reference', 'passenger_id', 'tour_id',
    'status', 'discount_amount', 'final_amount',
    'number_of_travelers', 'remarks',
])]
class Booking extends Model
{
    use HasFactory;
    protected $casts = ['status' => BookingStatus::class];

    public function passenger()
    {
        return $this->belongsTo(Passenger::class);
    }

    public function tour()
    {
        return $this->belongsTo(Tour::class);
    }

    public function flights()
    {
        return $this->hasMany(BookingFlight::class);
    }

    public function hotels()
    {
        return $this->hasMany(BookingHotel::class);
    }

    public function companions()
    {
        return $this->belongsToMany(Passenger::class, 'booking_companions');
    }

    public function payments()
    {
        return $this->hasMany(BookingPayment::class);
    }
}
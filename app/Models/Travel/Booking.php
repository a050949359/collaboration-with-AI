<?php

namespace App\Models\Travel;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'booking_reference', 'passenger_id', 'tour_id',
    'status', 'discount_amount', 'final_amount',
    'number_of_travelers', 'remarks',
])]
class Booking extends Model
{
    use HasFactory;

    protected $casts = ['status' => BookingStatus::class];

    /** @return BelongsTo<Passenger, $this> */
    public function passenger()
    {
        return $this->belongsTo(Passenger::class);
    }

    /** @return BelongsTo<Tour, $this> */
    public function tour()
    {
        return $this->belongsTo(Tour::class);
    }

    /** @return HasMany<BookingFlight, $this> */
    public function flights()
    {
        return $this->hasMany(BookingFlight::class);
    }

    /** @return HasMany<BookingHotel, $this> */
    public function hotels()
    {
        return $this->hasMany(BookingHotel::class);
    }

    /** @return BelongsToMany<Passenger, $this> */
    public function companions()
    {
        return $this->belongsToMany(Passenger::class, 'booking_companions')->withTimestamps();
    }

    /** @return HasMany<BookingPayment, $this> */
    public function payments()
    {
        return $this->hasMany(BookingPayment::class);
    }
}

<?php

namespace App\Models\Travel;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'email', 'phone'])]
class Passenger extends Model
{
    use HasFactory;

    /** @return HasMany<Booking, $this> */
    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    /** @return BelongsToMany<Booking, $this> */
    public function companionOf()
    {
        return $this->belongsToMany(Booking::class, 'booking_companions');
    }
}

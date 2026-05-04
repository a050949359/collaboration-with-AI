<?php

namespace App\Models\Travel;

use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'booking_id', 'type', 'method', 'amount', 'paid_at',
])]
class BookingPayment extends Model
{
    use HasFactory;
    protected $casts = [
        'type'   => PaymentType::class,
        'method' => PaymentMethod::class,
        'paid_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
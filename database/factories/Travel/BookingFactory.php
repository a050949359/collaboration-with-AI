<?php

namespace Database\Factories\Travel;

use App\Enums\BookingStatus;
use App\Models\Travel\Booking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        return [
            'booking_reference' => 'BT-' . now()->format('Y') . '-' . fake()->unique()->numerify('######'),
            'status'            => fake()->randomElement([
                                       ...array_fill(0, 7, BookingStatus::Confirmed),
                                       BookingStatus::Pending,
                                       BookingStatus::Cancelled,
                                       BookingStatus::Refunded,
                                   ])->value,
            'discount_amount'     => 0,
            'final_amount'        => 0,
            'number_of_travelers' => 1,
        ];
    }
}

<?php

namespace Database\Factories\Travel;

use App\Enums\RoomType;
use App\Models\Travel\BookingHotel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingHotel>
 */
class BookingHotelFactory extends Factory
{
    protected $model = BookingHotel::class;

    public function definition(): array
    {
        $checkIn           = fake()->dateTimeBetween('+15 days', '+120 days');
        $nights            = fake()->numberBetween(1, 7);
        $checkOut          = (clone $checkIn)->modify("+{$nights} days");
        $costPricePerNight = fake()->numberBetween(1500, 8000);

        return [
            'hotel_name'           => fake()->company() . ' Hotel',
            'check_in_date'        => $checkIn->format('Y-m-d'),
            'check_out_date'       => $checkOut->format('Y-m-d'),
            'nights'               => $nights,
            'room_type'            => fake()->randomElement([
                                          ...array_fill(0, 5, RoomType::Double),
                                          RoomType::Twin,
                                          RoomType::Single,
                                          RoomType::Suite,
                                          RoomType::Deluxe,
                                      ])->value,
            'number_of_rooms'      => 1,
            'cost_price_per_night' => $costPricePerNight,
            'total_cost_price'     => $costPricePerNight * $nights,
        ];
    }
}

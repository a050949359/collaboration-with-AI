<?php

namespace Database\Factories\Travel;

use App\Enums\CabinClass;
use App\Models\Airports\Airports;
use App\Models\Travel\TourFlight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TourFlight>
 */
class TourFlightFactory extends Factory
{
    protected $model = TourFlight::class;

    public function definition(): array
    {
        static $airportIds = null;
        static $taiwanIds  = null;

        if ($airportIds === null) {
            $airportIds = Airports::where('scheduled_service', true)
                ->whereNotNull('iata_code')
                ->whereNotIn('iata_code', ['TPE', 'KHH', 'RMQ', 'TSA'])
                ->pluck('id')
                ->toArray();

            $taiwanIds = Airports::whereIn('iata_code', ['TPE', 'KHH', 'RMQ', 'TSA'])
                ->pluck('id')
                ->toArray();
        }

        $departure = fake()->dateTimeBetween('+15 days', '+120 days');

        return [
            'flight_number'          => strtoupper(fake()->lexify('??')) . fake()->numerify('####'),
            'cabin_class'            => fake()->randomElement([
                                            ...array_fill(0, 7, CabinClass::Economy),
                                            CabinClass::PremiumEconomy,
                                            CabinClass::Business,
                                            CabinClass::First,
                                        ])->value,
            'origin_airport_id'      => fake()->randomElement($taiwanIds),
            'destination_airport_id' => fake()->randomElement($airportIds),
            'departure_time'         => $departure,
            'arrival_time'           => (clone $departure)->modify('+' . fake()->numberBetween(2, 14) . ' hours'),
            'cost_price'             => fake()->numberBetween(3000, 40000),
        ];
    }
}

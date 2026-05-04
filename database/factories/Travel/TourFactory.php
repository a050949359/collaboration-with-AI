<?php

namespace Database\Factories\Travel;

use App\Models\Travel\Tour;
use App\Enums\TourType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tour>
 */
class TourFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement([...array_fill(0, 9, TourType::Group),TourType::Fit,]);
        $code_prefix = $type === TourType::Group ? 'G' : 'F';
        $departure = fake()->dateTimeBetween('+15 days', '+120 days');
        $duration = fake()->numberBetween(5, 14);
        $return = (clone $departure)->modify("+$duration days");

        return [
            'tour_leader_id' => fake()->numberBetween(1, 20),
            'type' => $type->value,
            'code' => $code_prefix . '-' . \Carbon\Carbon::instance($departure)->format('Ymd') . '-' . fake()->unique()->numerify('######'),
            'name' => rtrim(fake()->sentence(7), '.'),
            'departure_date' => $departure->format('Y-m-d'),
            'return_date' => $return->format('Y-m-d'),
            'duration' => $duration,
            'selling_price' => fake()->numberBetween(8000, 30000),
            'target_profit' => fake()->numberBetween(1000, 3000),
            'min_pax' => fake()->numberBetween(3, 8) * 2,
            'max_pax' => fake()->numberBetween(10, 20) * 2,
        ];
    }
}

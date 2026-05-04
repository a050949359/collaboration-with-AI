<?php

namespace Database\Factories\Travel;

use App\Models\Travel\Passenger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Passenger>
 */
class PassengerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $dt = fake()->dateTimeBetween('-2 years', 'now');
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09' . fake()->numerify('########'),
            'created_at' => $dt,
            'updated_at' => $dt,
        ];
    }
}

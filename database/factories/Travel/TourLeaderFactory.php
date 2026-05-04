<?php

namespace Database\Factories\Travel;

use App\Models\Travel\TourLeader;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TourLeader>
 */
class TourLeaderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09' . fake()->numerify('########'),
            'license_number' => 'TL' . fake()->numerify('######'),
        ];
    }
}

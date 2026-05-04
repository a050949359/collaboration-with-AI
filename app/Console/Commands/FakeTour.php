<?php

namespace App\Console\Commands;

use App\Models\Travel\Tour;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fake:tour')]
#[Description('Create 20 fake tours for testing purposes')]
class FakeTour extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        Tour::factory()->count(20)
            ->sequence(function () {
                $dep    = fake()->dateTimeBetween('+100 days', '+120 days');
                $dur    = fake()->numberBetween(5, 14);
                $type   = fake()->randomElement([...array_fill(0, 9, 'G'), 'F']);
                $prefix = $type === 'G' ? 'G' : 'F';

                return [
                    'type'           => $type === 'G' ? 'group' : 'fit',
                    'departure_date' => $dep,
                    'duration'       => $dur,
                    'return_date'    => (clone $dep)->modify("+{$dur} days"),
                    'code'           => "{$prefix}-" . \Carbon\Carbon::instance($dep)->format('Ymd') . '-' . fake()->numerify('######'),
                ];
            })
            ->create();

        $this->info('Created 20 fake tours.');
    }
}

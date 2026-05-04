<?php

namespace Database\Seeders;

use App\Models\Travel\TourLeader;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TourLeaderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        TourLeader::factory()->count(19)->create();
    }
}

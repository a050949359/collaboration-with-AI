<?php

namespace Database\Seeders;

use App\Models\Travel\Passenger;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PassengerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $chunks = collect(range(1, 100))  // 100 次 × 1000 筆
        ->each(function () {
            $data = Passenger::factory()
                ->count(1000)
                ->make()                         // make() 只產資料，不存 DB
                ->map(fn ($p) => [
                    'name'       => $p->name,
                    'email'      => $p->email,
                    'phone'      => $p->phone,
                    'created_at' => $p->created_at,
                    'updated_at' => $p->updated_at,
                ])
                ->toArray();

            DB::table('passengers')->insert($data);
        });
    }
}

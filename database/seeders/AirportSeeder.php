<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Airport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AirportSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/airports.csv');

        if (! file_exists($path)) {
            $this->command->error("找不到 CSV：{$path}");
            return;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);   // 讀取標題列
        $header = array_map('trim', $header);

        $chunk  = [];
        $count  = 0;
        $now    = now();

        DB::table('airports')->truncate();

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, $row);

            $chunk[] = [
                'ident'              => $data['ident'] ?? null,
                'type'               => $this->validType($data['type'] ?? ''),
                'name'               => $data['name'] ?? '',
                'latitude_deg'       => $data['latitude_deg'] !== '' ? (float) $data['latitude_deg'] : null,
                'longitude_deg'      => $data['longitude_deg'] !== '' ? (float) $data['longitude_deg'] : null,
                'elevation_ft'       => $data['elevation_ft'] !== '' ? (int) $data['elevation_ft'] : null,
                'continent'          => $data['continent'] !== '' ? $data['continent'] : null,
                'iso_country'        => $data['iso_country'] !== '' ? $data['iso_country'] : null,
                'iso_region'         => $data['iso_region'] !== '' ? $data['iso_region'] : null,
                'municipality'       => $data['municipality'] !== '' ? $data['municipality'] : null,
                'scheduled_service'  => ($data['scheduled_service'] ?? 'no') === 'yes',
                'icao_code'          => $data['icao_code'] !== '' ? $data['icao_code'] : null,
                'iata_code'          => $data['iata_code'] !== '' ? $data['iata_code'] : null,
                'gps_code'           => $data['gps_code'] !== '' ? $data['gps_code'] : null,
                'local_code'         => $data['local_code'] !== '' ? $data['local_code'] : null,
                'home_link'          => $data['home_link'] !== '' ? $data['home_link'] : null,
                'wikipedia_link'     => $data['wikipedia_link'] !== '' ? $data['wikipedia_link'] : null,
                'keywords'           => $data['keywords'] !== '' ? $data['keywords'] : null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];

            // 每 500 筆批次寫入，避免記憶體爆炸
            if (count($chunk) >= 500) {
                DB::table('airports')->insert($chunk);
                $count += count($chunk);
                $chunk  = [];
                $this->command->info("已匯入 {$count} 筆...");
            }
        }

        // 寫入剩餘資料
        if (! empty($chunk)) {
            DB::table('airports')->insert($chunk);
            $count += count($chunk);
        }

        fclose($handle);
        $this->command->info("完成！共匯入 {$count} 筆機場資料。");
    }

    private function validType(string $type): string
    {
        $valid = ['large_airport', 'medium_airport', 'small_airport', 'heliport', 'seaplane_base', 'closed'];
        return in_array($type, $valid) ? $type : 'small_airport';
    }
}

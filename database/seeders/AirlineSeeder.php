<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AirlineSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/airlines.csv');

        if (! file_exists($path)) {
            $this->command->error("找不到 CSV：{$path}");
            return;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $header = array_map('trim', $header);

        $chunk = [];
        $count = 0;
        $now   = now();

        DB::table('airlines')->truncate();

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, array_pad($row, count($header), ''));

            $nameEn = trim($data['AirlineName.En'] ?? '');
            if ($nameEn === '') {
                continue;
            }

            $iata = trim($data['AirlineIATA'] ?? '') ?: null;

            $chunk[] = [
                'iata'        => $iata,
                'icao'        => trim($data['AirlineICAO'] ?? '') ?: null,
                'name_en'     => $nameEn,
                'name_zh_tw'  => trim($data['AirlineName.Zh_tw'] ?? '') ?: null,
                'alias_en'    => trim($data['AirlineNameAlias.En'] ?? '') ?: null,
                'alias_zh_tw' => trim($data['AirlineNameAlias.Zh_tw'] ?? '') ?: null,
                'nationality' => trim($data['AirlineNationality'] ?? '') ?: null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];

            if (count($chunk) >= 500) {
                DB::table('airlines')->insertOrIgnore($chunk);
                $count += count($chunk);
                $chunk  = [];
                $this->command->info("已匯入 {$count} 筆...");
            }
        }

        if (! empty($chunk)) {
            DB::table('airlines')->insertOrIgnore($chunk);
            $count += count($chunk);
        }

        fclose($handle);
        $this->command->info("完成！共匯入 {$count} 筆航空公司資料。");
    }
}

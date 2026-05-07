<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CountryRecognitionSeeder extends Seeder
{
    private const CSV_PATH = 'database/data/country_list_clean.csv';

    public function run(): void
    {
        $path = base_path(self::CSV_PATH);

        if (!is_file($path)) {
            $this->command->error('CSV not found: ' . self::CSV_PATH);
            return;
        }

        DB::table('countries')->update(['is_recognized' => false]);

        $recognized = 0;
        $enriched   = 0;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $cols     = str_getcsv($line);
            $nameZhTw = trim($cols[0] ?? '');
            $alpha2   = strtoupper(trim($cols[3] ?? ''));

            if (!$alpha2) continue;

            $country = DB::table('countries')->where('code', $alpha2)->first();
            if (!$country) continue;

            $update = ['is_recognized' => true];

            if ($nameZhTw && !$country->name_zh_tw) {
                $update['name_zh_tw'] = $nameZhTw;
                $enriched++;
            }

            DB::table('countries')->where('code', $alpha2)->update($update);
            $recognized++;
        }

        $this->command->info("is_recognized = true: {$recognized}");
        $this->command->info("name_zh_tw 補值:    {$enriched}");
    }
}

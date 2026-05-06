<?php

namespace App\Console\Commands;

use App\Models\Aviation\Country;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportCountries extends Command
{
    protected $signature = 'import:countries {--fetch : Re-fetch from Wikidata and overwrite cache} {--dry-run : Preview without saving}';
    protected $description = 'Import countries from Wikidata (cache: storage/app/private/wikidata_countries.json)';

    private const ENDPOINT   = 'https://query.wikidata.org/sparql';
    private const CACHE_PATH = 'storage/app/private/wikidata_countries.json';

    private const SPARQL = <<<'SPARQL'
SELECT ?code ?nameEn ?nameZhTw ?nameZh ?alpha3 ?numeric ?capital ?phoneCode
WHERE {
  ?country wdt:P297 ?code .
  OPTIONAL { ?country rdfs:label ?nameEn   . FILTER(LANG(?nameEn)   = "en") }
  OPTIONAL { ?country rdfs:label ?nameZhTw . FILTER(LANG(?nameZhTw) = "zh-tw") }
  OPTIONAL { ?country rdfs:label ?nameZh   . FILTER(LANG(?nameZh)   = "zh") }
  OPTIONAL { ?country wdt:P298 ?alpha3 . }
  OPTIONAL { ?country wdt:P299 ?numeric . }
  OPTIONAL {
    ?country wdt:P36 ?cap .
    ?cap rdfs:label ?capital .
    FILTER(LANG(?capital) = "en")
  }
  OPTIONAL { ?country wdt:P474 ?phoneCode . }
}
ORDER BY ?code
SPARQL;

    public function handle(): int
    {
        $cachePath = base_path(self::CACHE_PATH);

        if ($this->option('fetch') || !file_exists($cachePath)) {
            $this->info('Fetching from Wikidata...');
            try {
                $response = Http::withHeaders([
                    'Accept'     => 'application/sparql-results+json',
                    'User-Agent' => 'collaboration-with-AI/1.0 (haroldchen@besttour.com.tw)',
                ])->timeout(60)->get(self::ENDPOINT, ['query' => self::SPARQL, 'format' => 'json']);

                abort_unless($response->successful(), 500, 'HTTP ' . $response->status());
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                return 1;
            }
            file_put_contents($cachePath, $response->body());
            $this->info('Saved to ' . self::CACHE_PATH);
        } else {
            $this->info('Using cache: ' . self::CACHE_PATH);
        }

        $bindings = json_decode(file_get_contents($cachePath), true)['results']['bindings'] ?? [];

        // Group by code, collect multi-value fields
        $groups = [];
        foreach ($bindings as $b) {
            $code = strtoupper($b['code']['value'] ?? '');
            if (strlen($code) !== 2) continue;
            $groups[$code][] = $b;
        }

        $this->info('Unique codes: ' . count($groups));

        foreach ($groups as $code => $rows) {
            $data = $this->merge($rows);

            if ($this->option('dry-run')) {
                $this->line("{$code} | {$data['name_en']} | {$data['name_zh_tw']} | {$data['capital']} | {$data['phone_code']} | {$data['notes']}");
                continue;
            }

            Country::updateOrCreate(['code' => $code], $data);
        }

        if (!$this->option('dry-run')) {
            $this->info('Done.');
        }

        return 0;
    }

    private function merge(array $rows): array
    {
        $first = fn(string $field) => collect($rows)->pluck("{$field}.value")->filter()->first();
        $all   = fn(string $field) => collect($rows)->pluck("{$field}.value")->filter()->unique()->values()->all();

        $capitals   = $all('capital');
        $phoneCodes = $all('phoneCode');

        $notes = [];
        if (count($capitals) > 1)   $notes[] = 'capitals: '    . implode(', ', array_slice($capitals,   1));
        if (count($phoneCodes) > 1) $notes[] = 'phone_codes: ' . implode(', ', array_slice($phoneCodes, 1));

        return [
            'alpha3'     => $first('alpha3'),
            'numeric'    => $first('numeric'),
            'name_en'    => $first('nameEn'),
            'name_zh_tw' => $first('nameZhTw'),
            'name_zh'    => $first('nameZh'),
            'capital'    => $capitals[0]   ?? null,
            'phone_code' => $phoneCodes[0] ?? null,
            'notes'      => $notes ? implode('; ', $notes) : null,
        ];
    }
}

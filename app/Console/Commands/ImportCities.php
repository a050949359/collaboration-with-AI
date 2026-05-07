<?php

namespace App\Console\Commands;

use App\Models\Aviation\City;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImportCities extends Command
{
    protected $signature = 'import:cities
        {--type=   : Wikidata QID to fetch (e.g. Q515)}
        {--after=0 : Last QID numeric id for cursor pagination}
        {--id=     : Fetch specific Wikidata IDs, comma-separated (e.g. Q727,Q90)}
        {--update  : Re-fetch all cities in DB that have null country_code}';

    protected $description = 'Import cities from Wikidata. No args = dispatch all batches to queue.';

    private const ENDPOINT   = 'https://query.wikidata.org/sparql';
    private const BATCH_SIZE = 1000;
    private const DELAY_SEC  = 10;

    public function handle(): int
    {
        if ($this->option('update')) {
            return $this->updateNulls();
        }

        if ($this->option('id')) {
            return $this->fetchById((string) $this->option('id'));
        }

        return $this->option('type')
            ? $this->fetchBatch((string) $this->option('type'), (int) $this->option('after'))
            : $this->dispatchAll();
    }

    private function dispatchAll(): int
    {
        $this->info('Querying Q515 subclasses...');

        $sparql = <<<'SPARQL'
SELECT DISTINCT ?type
WHERE {
  { BIND(wd:Q515 AS ?type) }
  UNION
  { ?type wdt:P279+ wd:Q515 . }
}
SPARQL;

        try {
            $response = Http::withHeaders([
                'Accept'     => 'application/sparql-results+json',
                'User-Agent' => 'collaboration-with-AI/1.0 (haroldchen@besttour.com.tw)',
            ])->timeout(90)->get(self::ENDPOINT, ['query' => $sparql, 'format' => 'json']);

            if (!$response->successful()) {
                $this->error('HTTP ' . $response->status());
                return 1;
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $types = $response->json('results.bindings') ?? [];
        $this->info('Found ' . count($types) . ' types.');

        $delay = 0;

        foreach ($types as $row) {
            $type = basename($row['type']['value']);
            Artisan::queue('import:cities', ['--type' => $type, '--after' => 0])->delay($delay);
            $delay += self::DELAY_SEC;
        }

        $this->info("Dispatched " . count($types) . " jobs.");

        return 0;
    }

    private function parseCoords(?string $point): array
    {
        if (!$point || !preg_match('/Point\(([+-]?\d+\.?\d*) ([+-]?\d+\.?\d*)\)/', $point, $m)) {
            return [null, null];
        }
        return [$m[2], $m[1]]; // Point(lng lat) → [lat, lng]
    }

    private function updateNulls(): int
    {
        $ids = City::whereNull('country_code')->pluck('wikidata_id');
        $total = $ids->count();

        if ($total === 0) {
            $this->info('No cities with null country_code.');
            return 0;
        }

        $this->info("Found {$total} cities with null country_code, processing in batches of 100...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($ids->chunk(100) as $chunk) {
            $this->fetchById($chunk->implode(','));
            $bar->advance($chunk->count());
            sleep(3);
        }

        $bar->finish();
        $this->line('');
        $this->info('Done.');
        return 0;
    }

    private function fetchById(string $idOption): int
    {
        $ids    = array_filter(array_map('trim', explode(',', $idOption)));
        $values = implode(' ', array_map(fn($id) => "wd:{$id}", $ids));

        $sparql = <<<SPARQL
SELECT ?city ?coords ?nameEn ?nameZhTw ?nameZh ?countryCode
WHERE {
  VALUES ?city { {$values} }
  ?city wdt:P625 ?coords .
  OPTIONAL { ?city rdfs:label ?nameEn   . FILTER(LANG(?nameEn)   = "en") }
  OPTIONAL { ?city rdfs:label ?nameZhTw . FILTER(LANG(?nameZhTw) = "zh-tw") }
  OPTIONAL { ?city rdfs:label ?nameZh   . FILTER(LANG(?nameZh)   = "zh") }
  OPTIONAL { ?city wdt:P131*/wdt:P17 ?country . ?country (wdt:P297|wdt:P17/wdt:P297) ?countryCode . }
}
SPARQL;

        try {
            $response = Http::withHeaders([
                'Accept'     => 'application/sparql-results+json',
                'User-Agent' => 'collaboration-with-AI/1.0 (haroldchen@besttour.com.tw)',
            ])->timeout(30)->get(self::ENDPOINT, ['query' => $sparql, 'format' => 'json']);

            if (!$response->successful()) {
                $this->error("HTTP {$response->status()}");
                return 1;
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $bindings = $response->json('results.bindings') ?? [];
        $grouped  = [];

        foreach ($bindings as $b) {
            $wikidataId = basename($b['city']['value'] ?? '');
            if (!$wikidataId) continue;

            [$lat, $lng] = $this->parseCoords($b['coords']['value'] ?? null);

            if (!isset($grouped[$wikidataId])) {
                $grouped[$wikidataId] = [
                    'name_en'      => $b['nameEn']['value']    ?? null,
                    'name_zh_tw'   => $b['nameZhTw']['value']  ?? null,
                    'name_zh'      => $b['nameZh']['value']    ?? null,
                    'country_code' => isset($b['countryCode']) ? strtoupper($b['countryCode']['value']) : null,
                    'latitude'     => $lat,
                    'longitude'    => $lng,
                    'population'   => null,
                ];
            } else {
                $r = &$grouped[$wikidataId];
                if (!$r['name_en']      && isset($b['nameEn']))      $r['name_en']      = $b['nameEn']['value'];
                if (!$r['name_zh_tw']   && isset($b['nameZhTw']))    $r['name_zh_tw']   = $b['nameZhTw']['value'];
                if (!$r['name_zh']      && isset($b['nameZh']))      $r['name_zh']      = $b['nameZh']['value'];
                if (!$r['country_code'] && isset($b['countryCode'])) $r['country_code'] = strtoupper($b['countryCode']['value']);
            }
        }

        foreach ($grouped as $wikidataId => $data) {
            City::updateOrCreate(['wikidata_id' => $wikidataId], $data);
            $this->line("{$wikidataId} | {$data['name_en']} | {$data['country_code']}");
        }

        $this->info('Done. ' . count($grouped) . ' saved.');
        return 0;
    }

    private function fetchBatch(string $type, int $after): int
    {
        $limit  = self::BATCH_SIZE;
        $sparql = <<<SPARQL
SELECT ?city ?coords ?nameEn ?nameZhTw ?nameZh ?countryCode
WHERE {
  ?city wdt:P31 wd:{$type} .
  ?city wdt:P625 ?coords .
  OPTIONAL { ?city rdfs:label ?nameEn   . FILTER(LANG(?nameEn)   = "en") }
  OPTIONAL { ?city rdfs:label ?nameZhTw . FILTER(LANG(?nameZhTw) = "zh-tw") }
  OPTIONAL { ?city rdfs:label ?nameZh   . FILTER(LANG(?nameZh)   = "zh") }
  OPTIONAL { ?city wdt:P131*/wdt:P17 ?country . ?country (wdt:P297|wdt:P17/wdt:P297) ?countryCode . }
  BIND(xsd:integer(STRAFTER(STR(?city), "entity/Q")) AS ?cityId)
  FILTER(?cityId > {$after})
}
ORDER BY ?cityId
LIMIT {$limit}
SPARQL;

        try {
            $response = Http::withHeaders([
                'Accept'     => 'application/sparql-results+json',
                'User-Agent' => 'collaboration-with-AI/1.0 (haroldchen@besttour.com.tw)',
            ])->timeout(90)->get(self::ENDPOINT, ['query' => $sparql, 'format' => 'json']);

            if (!$response->successful()) {
                Log::error("[FAIL] import:cities type={$type} after={$after} HTTP={$response->status()}");
                return 1;
            }
        } catch (\Throwable $e) {
            Log::error("[FAIL] import:cities type={$type} after={$after} {$e->getMessage()}");
            return 1;
        }

        $bindings = $response->json('results.bindings') ?? [];

        if (empty($bindings)) {
            Log::info("[EMPTY] import:cities type={$type} after={$after}");
            return 0;
        }

        // 同一城市可能有多列（多個 P17 歷史政權），先合併取最佳值再寫入
        $grouped = [];
        foreach ($bindings as $b) {
            $wikidataId = basename($b['city']['value'] ?? '');
            if (!$wikidataId) continue;

            if (!isset($grouped[$wikidataId])) {
                [$lat, $lng] = $this->parseCoords($b['coords']['value'] ?? null);
                $grouped[$wikidataId] = [
                    'name_en'      => $b['nameEn']['value']    ?? null,
                    'name_zh_tw'   => $b['nameZhTw']['value']  ?? null,
                    'name_zh'      => $b['nameZh']['value']    ?? null,
                    'country_code' => isset($b['countryCode']) ? strtoupper($b['countryCode']['value']) : null,
                    'latitude'     => $lat,
                    'longitude'    => $lng,
                    'population'   => null,
                ];
            } else {
                // 補缺漏欄位，已有值的不覆蓋
                $r = &$grouped[$wikidataId];
                if (!$r['name_en']      && isset($b['nameEn']))      $r['name_en']      = $b['nameEn']['value'];
                if (!$r['name_zh_tw']   && isset($b['nameZhTw']))    $r['name_zh_tw']   = $b['nameZhTw']['value'];
                if (!$r['name_zh']      && isset($b['nameZh']))      $r['name_zh']      = $b['nameZh']['value'];
                if (!$r['country_code'] && isset($b['countryCode'])) $r['country_code'] = strtoupper($b['countryCode']['value']);
            }
        }

        foreach ($grouped as $wikidataId => $data) {
            City::updateOrCreate(['wikidata_id' => $wikidataId], $data);
        }

        $count   = count($grouped);
        $lastId  = (int) filter_var(end($bindings)['city']['value'] ?? '', FILTER_SANITIZE_NUMBER_INT);
        Log::info("[OK] import:cities type={$type} after={$after} saved={$count} lastId={$lastId}");

        if ($count === self::BATCH_SIZE) {
            Artisan::queue('import:cities', [
                '--type'  => $type,
                '--after' => $lastId,
            ]);
        }

        sleep(self::DELAY_SEC);

        return 0;
    }
}

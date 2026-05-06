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
        {--type= : Wikidata QID to fetch (e.g. Q515)}
        {--after=0 : Last QID numeric id for cursor pagination}';

    protected $description = 'Import cities from Wikidata. No args = dispatch all batches to queue.';

    private const ENDPOINT   = 'https://query.wikidata.org/sparql';
    private const BATCH_SIZE = 1000;
    private const DELAY_SEC  = 10;

    public function handle(): int
    {
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

    private function fetchBatch(string $type, int $after): int
    {
        $limit  = self::BATCH_SIZE;
        $sparql = <<<SPARQL
SELECT ?city ?coords
WHERE {
  ?city wdt:P31 wd:{$type} .
  ?city wdt:P625 ?coords .
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
                Log::error("[FAIL] import:cities type={$type} offset={$offset} HTTP={$response->status()}");
                return 1;
            }
        } catch (\Throwable $e) {
            Log::error("[FAIL] import:cities type={$type} offset={$offset} {$e->getMessage()}");
            return 1;
        }

        $bindings = $response->json('results.bindings') ?? [];

        if (empty($bindings)) {
            Log::info("[EMPTY] import:cities type={$type} after={$after}");
            return 0;
        }

        foreach ($bindings as $b) {
            $wikidataId = basename($b['city']['value'] ?? '');
            if (!$wikidataId) continue;

            [$lat, $lng] = $this->parseCoords($b['coords']['value'] ?? null);

            City::updateOrCreate(
                ['wikidata_id' => $wikidataId],
                [
                    'name_en'      => $b['nameEn']['value']    ?? null,
                    'name_zh_tw'   => $b['nameZhTw']['value']  ?? null,
                    'name_zh'      => $b['nameZh']['value']    ?? null,
                    'country_code' => null,
                    'latitude'     => $lat,
                    'longitude'    => $lng,
                    'population'   => null,
                ]
            );
        }

        $count   = count($bindings);
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

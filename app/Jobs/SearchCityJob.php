<?php

namespace App\Jobs;

use App\Models\Aviation\City;
use App\Models\Aviation\CitySearchJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

class SearchCityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const SPARQL_ENDPOINT = 'https://query.wikidata.org/sparql';
    private const USER_AGENT      = 'collaboration-with-AI/1.0 (haroldchen@besttour.com.tw)';

    public function __construct(public int $jobId) {}

    public function handle(): void
    {
        $job = CitySearchJob::find($this->jobId);
        if (!$job) return;

        $job->update(['status' => 'processing']);

        try {
            $data = $this->fetchDetails($job->wikidata_qid, $job->country_code);

            if (!$data) {
                $job->update(['status' => 'failed', 'error' => 'No data returned from Wikidata']);
                return;
            }

            $city = City::updateOrCreate(
                ['wikidata_id' => $job->wikidata_qid],
                array_merge($data, ['submitted_by' => $job->submitted_by])
            );

            $job->update(['status' => 'success', 'city_id' => $city->id]);
        } catch (Throwable $e) {
            $job->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }

    public function failed(Throwable $exception): void
    {
        CitySearchJob::where('id', $this->jobId)
            ->update(['status' => 'failed', 'error' => $exception->getMessage()]);
    }

    private function fetchDetails(string $qid, string $countryCode): ?array
    {
        $sparql = <<<SPARQL
SELECT ?nameEn ?nameZhTw ?coords ?population ?timezone ?elevation ?area ?description ?image ?wikipedia ?postalCode
WHERE {
  OPTIONAL { wd:{$qid} rdfs:label ?nameEn   . FILTER(LANG(?nameEn)   = "en") }
  OPTIONAL { wd:{$qid} rdfs:label ?nameZhTw . FILTER(LANG(?nameZhTw) = "zh-tw") }
  OPTIONAL { wd:{$qid} wdt:P625  ?coords }
  OPTIONAL { wd:{$qid} wdt:P1082 ?population }
  OPTIONAL { wd:{$qid} wdt:P421  ?tzItem . ?tzItem wdt:P6687 ?timezone }
  OPTIONAL { wd:{$qid} wdt:P2044 ?elevation }
  OPTIONAL { wd:{$qid} wdt:P2046 ?area }
  OPTIONAL { wd:{$qid} schema:description ?description . FILTER(LANG(?description) = "zh-tw") }
  OPTIONAL { wd:{$qid} wdt:P18   ?image }
  OPTIONAL { wd:{$qid} wdt:P281  ?postalCode }
  OPTIONAL {
    ?article schema:about wd:{$qid} ;
             schema:inLanguage "zh-tw" ;
             schema:isPartOf <https://zh.wikipedia.org/> .
    BIND(STR(?article) AS ?wikipedia)
  }
}
LIMIT 1
SPARQL;

        $response = Http::withHeaders([
            'Accept'     => 'application/sparql-results+json',
            'User-Agent' => self::USER_AGENT,
        ])->timeout(20)->get(self::SPARQL_ENDPOINT, ['query' => $sparql, 'format' => 'json']);

        if (!$response->successful()) return null;

        $b = $response->json('results.bindings.0') ?? [];
        if (empty($b)) return null;

        [$lat, $lng] = $this->parseCoords($b['coords']['value'] ?? null);

        $imageFile = $b['image']['value'] ?? null;
        $imageUrl  = $imageFile
            ? 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode(basename($imageFile))
            : null;

        return [
            'wikidata_id'   => $qid,
            'name_en'       => $b['nameEn']['value']      ?? null,
            'name_zh_tw'    => $b['nameZhTw']['value']    ?? null,
            'country_code'  => strtoupper($countryCode),
            'latitude'      => $lat,
            'longitude'     => $lng,
            'population'    => isset($b['population']) ? (int) $b['population']['value'] : null,
            'timezone'       => $b['timezone']['value']   ?? null,
            'elevation'     => isset($b['elevation'])     ? (int) $b['elevation']['value'] : null,
            'area'          => isset($b['area'])          ? (int) $b['area']['value']      : null,
            'description'   => $b['description']['value'] ?? null,
            'image_url'     => $imageUrl,
            'wikipedia_url' => $b['wikipedia']['value']   ?? null,
            'postal_code'   => $b['postalCode']['value']  ?? null,
        ];
    }

    private function parseCoords(?string $point): array
    {
        if (!$point || !preg_match('/Point\(([+-]?\d+\.?\d*) ([+-]?\d+\.?\d*)\)/', $point, $m)) {
            return [null, null];
        }
        return [(float) $m[2], (float) $m[1]];
    }
}

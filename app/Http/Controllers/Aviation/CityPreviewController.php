<?php

namespace App\Http\Controllers\Aviation;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CityPreviewController extends Controller
{
    use ApiResponse;

    private const SPARQL_ENDPOINT = 'https://query.wikidata.org/sparql';
    private const USER_AGENT      = 'collaboration-with-AI/1.0 (haroldchen@besttour.com.tw)';

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'city_name'    => ['required', 'string', 'max:100'],
            'country_code' => ['required', 'string', 'size:2'],
        ]);

        $cityName    = $request->input('city_name');
        $countryCode = strtoupper($request->input('country_code'));

        $sparql = <<<SPARQL
SELECT DISTINCT ?city ?nameEn ?nameZhTw ?description WHERE {
  SERVICE wikibase:mwapi {
    bd:serviceParam wikibase:api "EntitySearch" ;
                    wikibase:endpoint "www.wikidata.org" ;
                    mwapi:search "{$cityName}" ;
                    mwapi:language "zh-tw" .
    ?city wikibase:apiOutputItem mwapi:item .
  }
  ?city wdt:P17/wdt:P297 "{$countryCode}" .
  OPTIONAL { ?city rdfs:label ?nameEn   . FILTER(LANG(?nameEn)   = "en") }
  OPTIONAL { ?city rdfs:label ?nameZhTw . FILTER(LANG(?nameZhTw) = "zh-tw") }
  OPTIONAL { ?city schema:description ?description . FILTER(LANG(?description) = "zh-tw") }
}
LIMIT 5
SPARQL;

        $response = Http::withHeaders([
            'Accept'     => 'application/sparql-results+json',
            'User-Agent' => self::USER_AGENT,
        ])->timeout(15)->get(self::SPARQL_ENDPOINT, ['query' => $sparql, 'format' => 'json']);

        if (!$response->successful()) {
            return $this->error('Wikidata unavailable', 503);
        }

        $bindings = $response->json('results.bindings') ?? [];

        $candidates = collect($bindings)->map(function ($b) {
            $qid = basename($b['city']['value'] ?? '');
            return [
                'qid'        => $qid,
                'name_en'    => $b['nameEn']['value']      ?? null,
                'name_zh_tw' => $b['nameZhTw']['value']    ?? null,
                'description'=> $b['description']['value'] ?? null,
                'url'        => "https://www.wikidata.org/wiki/{$qid}",
            ];
        })->filter(fn($c) => $c['qid'])->values();

        return $this->success($candidates);
    }
}

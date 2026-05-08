<?php

namespace App\Http\Controllers\Aviation;

use App\Http\Controllers\Controller;
use App\Models\Aviation\City;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CityPreviewController extends Controller
{
    use ApiResponse;

    private const USER_AGENT      = 'collaboration-with-AI/1.0 (haroldchen@besttour.com.tw)';
    private const ACTION_ENDPOINT = 'https://www.wikidata.org/w/api.php';

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'city_name' => ['required', 'string', 'max:100'],
        ]);

        $cityName = $request->input('city_name');

        $byQid = [];

        foreach (['zh-tw' => 'name_zh_tw', 'en' => 'name_en'] as $lang => $nameField) {
            $res = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(5)
                ->get(self::ACTION_ENDPOINT, [
                    'action'   => 'wbsearchentities',
                    'search'   => $cityName,
                    'language' => $lang,
                    'type'     => 'item',
                    'format'   => 'json',
                    'limit'    => 10,
                ]);

            if (!$res->successful()) continue;

            foreach ($res->json('search') ?? [] as $item) {
                $id = $item['id'] ?? '';
                if (!preg_match('/^Q\d+$/', $id)) continue;

                if (!isset($byQid[$id])) {
                    $byQid[$id] = ['name_zh_tw' => null, 'name_en' => null, 'description' => null, 'aliases' => []];
                }

                $byQid[$id][$nameField] = $item['label'] ?? null;

                if (!$byQid[$id]['description'] || $lang === 'zh-tw') {
                    $byQid[$id]['description'] = $item['description'] ?? null;
                }

                foreach ($item['aliases'] ?? [] as $alias) {
                    if (!in_array($alias, $byQid[$id]['aliases'])) {
                        $byQid[$id]['aliases'][] = $alias;
                    }
                }
            }
        }

        if (empty($byQid)) {
            return $this->success(collect());
        }

        $existingCities = City::whereIn('wikidata_id', array_keys($byQid))
            ->pluck('country_code', 'wikidata_id');

        $candidates = collect($byQid)->map(function ($data, $qid) use ($existingCities) {
            return [
                'qid'          => $qid,
                'name_en'      => $data['name_en'],
                'name_zh_tw'   => $data['name_zh_tw'],
                'description'  => $data['description'],
                'aliases'      => $data['aliases'],
                'url'          => "https://www.wikidata.org/wiki/{$qid}",
                'existing'     => $existingCities->has($qid),
                'country_code' => $existingCities->get($qid),
            ];
        })->values();

        return $this->success($candidates);
    }
}

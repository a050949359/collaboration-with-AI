<?php

namespace App\Http\Controllers\Aviation;

use App\Http\Controllers\Controller;
use App\Models\Aviation\Airports;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AirportStatsController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request): JsonResponse
    {
        $stats = Cache::remember('airport_stats', now()->addHours(6), function () {

            $byType = Airports::select('type', DB::raw('COUNT(*) as count'))
                ->groupBy('type')
                ->orderByDesc('count')
                ->pluck('count', 'type')
                ->toArray();

            $byContinent = Airports::select('continent', DB::raw('COUNT(*) as count'))
                ->whereNotNull('continent')
                ->groupBy('continent')
                ->orderByDesc('count')
                ->pluck('count', 'continent')
                ->toArray();

            $topCountries = Airports::select('iso_country', DB::raw('COUNT(*) as count'))
                ->whereNotNull('iso_country')
                ->groupBy('iso_country')
                ->orderByDesc('count')
                ->limit(20)
                ->pluck('count', 'iso_country')
                ->toArray();

            return [
                'total'             => Airports::count(),
                'scheduled_service' => Airports::where('scheduled_service', true)->count(),
                'by_type'           => $byType,
                'by_continent'      => $byContinent,
                'top_countries'     => $topCountries,
            ];
        });

        return $this->success($stats);
    }
}

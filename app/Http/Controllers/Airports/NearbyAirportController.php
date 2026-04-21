<?php

namespace App\Http\Controllers\Airports;

use App\Http\Controllers\Controller;
use App\Http\Resources\Airports\AirportResource;
use App\Models\Airports\Airports;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NearbyAirportController extends Controller
{
    use ApiResponse;

    // GET /api/v1/airports/nearby?lat=25.0777&lng=121.2322&radius=100&type=large_airport
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'lat'    => ['required', 'numeric', 'between:-90,90'],
            'lng'    => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'type'   => ['nullable', 'in:large_airport,medium_airport,small_airport,heliport,seaplane_base'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $airports = Airports::nearby(
                $request->float('lat'),
                $request->float('lng'),
                $request->float('radius', 100)
            )
            ->when($request->type, fn($q, $t) => $q->where('type', $t))
            ->limit($request->integer('limit', 10))
            ->get();

        return $this->success(
            AirportResource::collection($airports),
            "找到 {$airports->count()} 座附近機場"
        );
    }
}
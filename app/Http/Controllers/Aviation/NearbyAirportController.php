<?php

namespace App\Http\Controllers\Aviation;

use App\Enums\AirportType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Aviation\AirportResource;
use App\Models\Aviation\Airports;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NearbyAirportController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'lat'    => ['required', 'numeric', 'between:-90,90'],
            'lng'    => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'type'   => ['nullable', Rule::in(AirportType::searchable())],
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

<?php

namespace App\Http\Controllers\Aviation;

use App\Http\Controllers\Controller;
use App\Http\Resources\Aviation\CityResource;
use App\Models\Aviation\City;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CityController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'country_code' => ['required', 'string', 'size:2'],
        ]);

        $cities = City::where('country_code', strtoupper($request->country_code))
            ->orderByDesc('population')
            ->orderBy('name_en')
            ->get()
            ->map(fn($c) => new CityResource($c));

        return $this->success($cities);
    }
}

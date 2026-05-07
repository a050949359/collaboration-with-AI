<?php

namespace App\Http\Controllers\Aviation;

use App\Http\Controllers\Controller;
use App\Http\Resources\Aviation\CountryResource;
use App\Models\Aviation\Country;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CountryController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search'     => ['nullable', 'string', 'max:100'],
            'recognized' => ['nullable', 'boolean'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $countries = Country::filter($request->only(['search', 'recognized']))
            ->orderBy('name_en')
            ->paginate($request->integer('per_page', 50));

        return $this->paginated($countries->through(fn($c) => new CountryResource($c)));
    }

    public function show(string $code): JsonResponse
    {
        $country = Country::where('code', strtoupper($code))
            ->orWhere('alpha3', strtoupper($code))
            ->firstOrFail();

        return $this->success(new CountryResource($country));
    }
}

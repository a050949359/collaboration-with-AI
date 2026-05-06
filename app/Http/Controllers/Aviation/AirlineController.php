<?php

namespace App\Http\Controllers\Aviation;

use App\Http\Controllers\Controller;
use App\Http\Resources\Aviation\AirlineResource;
use App\Models\Aviation\Airline;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AirlineController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search'      => ['nullable', 'string', 'max:100'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $airlines = Airline::filter($request->only(['search', 'nationality']))
            ->orderBy('name_en')
            ->paginate($request->integer('per_page', 50));

        return $this->paginated($airlines->through(fn($a) => new AirlineResource($a)));
    }
}

<?php

namespace App\Http\Controllers\Airports;

use App\Http\Controllers\Controller;
use App\Http\Resources\Airports\AirportResource;
use App\Models\Airports\Airports;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AirportController extends Controller
{
    use ApiResponse;

    // GET /api/v1/airports
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search'    => ['nullable', 'string', 'max:100'],
            'type'      => ['nullable', 'in:large_airport,medium_airport,small_airport,heliport,seaplane_base,closed'],
            'continent' => ['nullable', 'in:AF,AN,AS,EU,NA,OC,SA'],
            'country'   => ['nullable', 'string', 'size:2'],
            'region'    => ['nullable', 'string', 'max:10'],
            'scheduled' => ['nullable', 'in:true,false,1,0'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $airports = Airports::filter($request->only([
                'search', 'type', 'continent', 'country', 'region', 'scheduled',
            ]))
            ->orderBy('type')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        $paginator = $airports->through(fn($a) => new AirportResource($a));

        return $this->paginated($paginator);
    }

    // GET /api/v1/airports/{ident}  ← 用 ident 而非 id，更語意化
    public function show(string $ident): JsonResponse
    {
        $airport = Airports::where('ident', strtoupper($ident))
            ->orWhere('iata_code', strtoupper($ident))
            ->firstOrFail();

        return $this->success(new AirportResource($airport));
    }
}
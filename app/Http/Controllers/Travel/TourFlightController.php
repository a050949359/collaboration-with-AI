<?php

namespace App\Http\Controllers\Travel;

use App\Models\Travel\Tour;
use App\Models\Travel\TourFlight;
use App\Http\Controllers\Controller;
use App\Http\Requests\Travel\TourFlightRequest;

class TourFlightController extends Controller
{
    public function index(Tour $tour)
    {
        return response()->json(
            TourFlight::where('tour_id', $tour->id)->get()
        );
    }

    public function store(TourFlightRequest $request, Tour $tour)
{
    $validated = $request->validated();

    $flight = TourFlight::create(['tour_id' => $tour->id, ...$validated]);

    return response()->json($flight, 201);
}

    public function destroy(Tour $tour, TourFlight $flight)
    {
        TourFlight::where('tour_id', $tour->id)->where('id', $flight->id)->delete();
        return response()->json(null, 204);
    }
}

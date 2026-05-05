<?php

namespace App\Http\Controllers\Travel;

use App\Models\Travel\Tour;
use App\Models\Travel\TourHotel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Travel\TourHotelRequest;
class TourHotelController extends Controller
{
    public function index(Tour $tour)
    {
        return response()->json(
            TourHotel::where('tour_id', $tour->id)->get()
        );
    }

    public function store(TourHotelRequest $request, Tour $tour)
{
    $validated = $request->validated();

    $nights = now()->parse($validated['check_in_date'])
                   ->diffInDays($validated['check_out_date']);

    $hotel = TourHotel::create([
        'tour_id'           => $tour->id,
        'nights'            => $nights,
        'total_cost_price'  => $validated['cost_price_per_night'] * $nights * $validated['number_of_rooms'],
        ...$validated,
    ]);

    return response()->json($hotel, 201);
}

    public function destroy(Tour $tour, TourHotel $hotel)
    {
        TourHotel::where('tour_id', $tour->id)->where('id', $hotel->id)->delete();
        return response()->json(null, 204);
    }
}

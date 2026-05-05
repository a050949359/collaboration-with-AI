<?php

namespace App\Http\Controllers\Travel;

use App\Http\Controllers\Controller;
use App\Models\Travel\Passenger;
use App\Models\Travel\Booking;
use App\Models\Travel\Tour;

class StatsController extends Controller
{
    public function index()
    {
        return response()->json([
            'passengers_count' => Passenger::count(),
            'bookings_count'   => Booking::count(),
            'tours_count'      => Tour::count(),
        ]);
    }
}
<?php

namespace App\Http\Controllers\Travel;

use App\Models\Travel\Passenger;
use App\Models\Travel\Booking;
use App\Models\Travel\BookingCompanion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Travel\PassengerRequest;
use Illuminate\Http\Request;

class PassengerController extends Controller
{
    public function index(PassengerRequest $request)
    {
        $validated = $request->validated();

        $filter = $validated['filter'] ?? null;

        $passengers = Passenger::query()
        ->when($filter === 'no_booking', fn($q) =>
            $q->whereDoesntHave('bookings')->whereDoesntHave('companionOf')
        )
        ->when($filter === 'companion_only', fn($q) =>
            $q->whereDoesntHave('bookings')->whereHas('companionOf')
            ->with('companionOf.tour')
        )
        ->when($filter === 'booker', fn($q) =>
            $q->whereHas('bookings')
            ->with(['bookings.payments', 'bookings.tour'])
        )
        ->get();

        return response()->json($passengers);
    }

    // TODO [Security]: integer ID 可被列舉（/passengers/1, /passengers/2 ...）
    // 解法：Passenger 加 uuid 欄位，getRouteKeyName() 回傳 'uuid'，
    // 同步更新 PassengerFactory、migration（補填現有資料）、前端改存 p.uuid。
    // booking 送出仍可用 integer id，UUID 僅用於對外 URL。
    public function show(Passenger $passenger)
    {
        return response()->json($passenger->only(['id', 'name']));
    }

    public function random(PassengerRequest $request)
    {
        $filter = $request->validated()['filter'] ?? null;

        switch ($filter) {
            case 'booker':
                $count = Booking::distinct('passenger_id')
                    ->count('passenger_id');

                if ($count === 0) 
                    break;

                $passengerId = Booking::select('passenger_id')
                    ->distinct()
                    ->skip(random_int(0, $count - 1))
                    ->value('passenger_id');
                $passenger = Passenger::with(['bookings.payments', 'bookings.tour'])
                    ->find($passengerId);
                break;

            case 'companion_only':
                $count = BookingCompanion::distinct('passenger_id')
                    ->count('passenger_id');

                if ($count === 0) 
                    break;

                $passengerId = BookingCompanion::select('passenger_id')
                    ->distinct()
                    ->skip(random_int(0, $count - 1))
                    ->value('passenger_id');
                $passenger = Passenger::with('companionOf.tour')->find($passengerId);
                break;

            case 'no_booking':
                $passenger = Passenger::query()
                    ->whereNotIn('id', Booking::select('passenger_id'))
                    ->whereNotIn('id', BookingCompanion::select('passenger_id'))
                    ->skip(random_int(0, 500))
                    ->first();
                break;

            default:
                $count = Passenger::count();
                $passenger = Passenger::with(['bookings.payments', 'bookings.tour', 'companionOf.tour'])
                    ->skip(random_int(0, $count - 1))
                    ->first();
                break;
        }

        return isset($passenger)
            ? response()->json($passenger)
            : response()->json(['message' => 'No passengers found'], 404);
    }

    public function lookup(Request $request)
    {
        $email = $request->query('email', '');

        if (!$email) {
            return response()->json(['message' => 'Email required'], 422);
        }

        $passenger = Passenger::where('email', $email)->first();

        return $passenger
            ? response()->json($passenger->only(['id', 'email']))
            : response()->json(['message' => '找不到此旅客'], 404);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:passengers,email'],
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $passenger = Passenger::create($validated);

        return response()->json($passenger->only(['id', 'email']), 201);
    }
}

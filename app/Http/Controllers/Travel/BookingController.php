<?php

namespace App\Http\Controllers\Travel;

use App\Enums\BookingStatus;
use App\Models\Travel\Tour;
use App\Models\Travel\Booking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Travel\BookingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function index(Request $request) {
        return response()->json(
            Booking::with(['tour', 'passenger', 'payments'])
                ->when($request->tour_id, fn($q, $id) => $q->where('tour_id', $id))
                ->orderByDesc('created_at')
                ->get()
    );
}

    public function store(BookingRequest $request) {
        $validated = $request->validated();

        $booking = DB::transaction(function () use ($validated) {
            // 鎖住 Tour 這一列，同一時間只有一個 transaction 能進到這裡
            $tour = Tour::lockForUpdate()->findOrFail($validated['tour_id']);

            // 模擬處理耗時，方便測試悲觀鎖排隊效果；上線前移除
            sleep(3);

            // 計算目前已佔位數（排除 cancelled / refunded）
            $occupiedPax = $tour->bookings()
                ->whereNotIn('status', [
                    BookingStatus::Cancelled->value,
                    BookingStatus::Refunded->value,
                ])
                ->sum('number_of_travelers');

            $remaining = $tour->max_pax - $occupiedPax;

            if ($validated['number_of_travelers'] > $remaining) {
                abort(422, "名額不足，剩餘 {$remaining} 位");
            }

            do {
                $ref = 'BT-' . now()->format('Y') . '-' . random_int(100000, 999999);
            } while (Booking::where('booking_reference', $ref)->exists());

            $booking = Booking::create([
                'booking_reference'   => $ref,
                'tour_id'             => $tour->id,
                'passenger_id'        => $validated['passenger_id'],
                'number_of_travelers' => $validated['number_of_travelers'],
                'discount_amount'     => $validated['discount_amount'] ?? 0,
                'final_amount'        => $validated['final_amount'],
                'status'              => BookingStatus::Reserved->value,
            ]);

            if (!empty($validated['companions'])) {
                $booking->companions()->sync($validated['companions']);
            }

            return $booking;
        });

        return response()->json($booking, 201);
    }
}

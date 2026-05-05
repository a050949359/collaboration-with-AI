<?php

namespace App\Http\Controllers\Travel;

use App\Enums\TourType;
use App\Models\Travel\Tour;
use App\Http\Controllers\Controller;
use App\Http\Requests\Travel\TourRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TourController extends Controller
{
    public function index(Request $request) {
        $onlyVacant = $request->boolean('has_vacancy');

        $tours = Tour::query()
            ->where('type', TourType::Group->value)
            ->withSum(
                ['bookings as booked_pax' => fn($q) => $q->whereNotIn('status', ['cancelled', 'refunded'])],
                'number_of_travelers'
            )
            ->get();

        if ($onlyVacant) {
            $tours = $tours->filter(fn($t) => ($t->booked_pax ?? 0) < $t->max_pax)->values();
        }

        $tours->each(fn($t) => $t->setAttribute('is_formed', ($t->booked_pax ?? 0) >= $t->min_pax));

        return response()->json($tours);
    }

    public function store(TourRequest $request) {
        $validated = $request->validated();

        if (empty($validated['code'])) {
            $prefix = match($validated['type']) {
                'group' => 'GRP',
                'fit'   => 'FIT',
                default => strtoupper(substr($validated['type'], 0, 3)),
            };
            $year = now()->parse($validated['departure_date'])->year;
            $seq  = Tour::where('code', 'like', "{$prefix}-{$year}-%")->count() + 1;
            do {
                $validated['code'] = sprintf('%s-%d-%03d', $prefix, $year, $seq++);
            } while (Tour::where('code', $validated['code'])->exists());
        }

        $tour = Tour::create($validated);
        return response()->json($tour, 201);
    }

    public function update(TourRequest $request, Tour $tour) {
        $tour->update($request->validated());
        return response()->json($tour);
    }
}

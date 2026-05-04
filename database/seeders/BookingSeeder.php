<?php

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Enums\TourType;
use App\Models\Travel\Booking;
use App\Models\Travel\BookingCompanion;
use App\Models\Travel\BookingFlight;
use App\Models\Travel\BookingHotel;
use App\Models\Travel\BookingPayment;
use App\Models\Travel\Passenger;
use App\Models\Travel\Tour;
use App\Models\Travel\TourFlight;
use App\Models\Travel\TourHotel;
use Illuminate\Database\Seeder;

class BookingSeeder extends Seeder
{
    public function run(): void
    {
        $passengerIds = Passenger::pluck('id')->toArray();

        Tour::lazy()->each(function (Tour $tour) use ($passengerIds) {
            $isGroup          = $tour->type === TourType::Group;
            $usedPassengerIds = [];

            if ($isGroup) {
                $totalPax    = fake()->numberBetween(10, 40);
                $isChartered = fake()->boolean(5); // 10% 機率包團

                if ($isChartered) {
                    $this->createBookingWithCompanions($tour, $passengerIds, $usedPassengerIds, $totalPax);
                } else {
                    $remainingPax = $totalPax;

                    while ($remainingPax > 0) {
                        $partySize = min($remainingPax, fake()->numberBetween(1, 4));
                        $this->createBookingWithCompanions($tour, $passengerIds, $usedPassengerIds, $partySize);
                        $remainingPax -= $partySize;
                    }
                }

                $this->createGroupFlights($tour);
                $this->createGroupHotels($tour, \count($usedPassengerIds));
            } else {
                $partySize = fake()->numberBetween(1, 6);
                $booking   = $this->createBookingWithCompanions($tour, $passengerIds, $usedPassengerIds, $partySize);

                $this->createFitFlight($booking->id, $tour);
                $this->createFitHotel($booking->id, $tour);
            }
        });
    }

    private function createBookingWithCompanions(Tour $tour, array $passengerIds, array &$usedPassengerIds, int $partySize): Booking
    {
        do {
            $payerId = fake()->randomElement($passengerIds);
        } while (\in_array($payerId, $usedPassengerIds));
        $usedPassengerIds[] = $payerId;

        $finalAmount = $tour->selling_price * $partySize;

        $booking = Booking::factory()->create([
            'tour_id'             => $tour->id,
            'passenger_id'        => $payerId,
            'final_amount'        => $finalAmount,
            'number_of_travelers' => $partySize,
        ]);

        $this->createPayment($booking->id, $finalAmount, $partySize);

        for ($j = 1; $j < $partySize; $j++) {
            do {
                $companionId = fake()->randomElement($passengerIds);
            } while (\in_array($companionId, $usedPassengerIds));
            $usedPassengerIds[] = $companionId;

            BookingCompanion::create([
                'booking_id'   => $booking->id,
                'passenger_id' => $companionId,
            ]);
        }

        return $booking;
    }

    private function createPayment(int $bookingId, float $finalAmount, int $partySize): void
    {
        $isFullPayment = fake()->boolean(20);
        $amount        = $isFullPayment
            ? $finalAmount
            : fake()->numberBetween(10, 15) * 1000 * $partySize;

        BookingPayment::create([
            'booking_id' => $bookingId,
            'type'       => $isFullPayment ? PaymentType::FinalPayment->value : PaymentType::Deposit->value,
            'method'     => fake()->randomElement(PaymentMethod::cases())->value,
            'amount'     => $amount,
            'paid_at'    => now()->subDays(fake()->numberBetween(1, 30)),
        ]);
    }

    private function createFitFlight(int $bookingId, Tour $tour): void
    {
        $outbound = BookingFlight::factory()->create([
            'booking_id'     => $bookingId,
            'departure_time' => now()->parse($tour->departure_date)->setTime(fake()->numberBetween(6, 10), 0),
            'arrival_time'   => now()->parse($tour->departure_date)->setTime(fake()->numberBetween(11, 18), 0),
        ]);

        BookingFlight::factory()->create([
            'booking_id'             => $bookingId,
            'origin_airport_id'      => $outbound->destination_airport_id,
            'destination_airport_id' => $outbound->origin_airport_id,
            'departure_time'         => now()->parse($tour->return_date)->setTime(fake()->numberBetween(10, 15), 0),
            'arrival_time'           => now()->parse($tour->return_date)->setTime(fake()->numberBetween(16, 22), 0),
        ]);
    }

    private function createFitHotel(int $bookingId, Tour $tour): void
    {
        $totalNights  = now()->parse($tour->departure_date)->diffInDays($tour->return_date);
        $hotelCount   = fake()->numberBetween(1, min(3, $totalNights));
        $currentDate  = now()->parse($tour->departure_date);

        for ($i = 0; $i < $hotelCount; $i++) {
            $isLast = $i === $hotelCount - 1;
            $nights = $isLast
                ? now()->parse($tour->return_date)->diffInDays($currentDate)
                : fake()->numberBetween(1, (int) floor($totalNights / $hotelCount));

            $checkOut          = $currentDate->copy()->addDays($nights);
            $costPricePerNight = fake()->numberBetween(1500, 8000);

            BookingHotel::factory()->create([
                'booking_id'           => $bookingId,
                'check_in_date'        => $currentDate->format('Y-m-d'),
                'check_out_date'       => $checkOut->format('Y-m-d'),
                'nights'               => $nights,
                'cost_price_per_night' => $costPricePerNight,
                'total_cost_price'     => $costPricePerNight * $nights,
            ]);

            $currentDate = $checkOut;
        }
    }

    private function createGroupFlights(Tour $tour): void
    {
        $outbound = TourFlight::factory()->create([
            'tour_id'        => $tour->id,
            'departure_time' => now()->parse($tour->departure_date)->setTime(8, 0),
            'arrival_time'   => now()->parse($tour->departure_date)->setTime(12, 0),
        ]);

        TourFlight::factory()->create([
            'tour_id'                => $tour->id,
            'origin_airport_id'      => $outbound->destination_airport_id,
            'destination_airport_id' => $outbound->origin_airport_id,
            'departure_time'         => now()->parse($tour->return_date)->setTime(15, 0),
            'arrival_time'           => now()->parse($tour->return_date)->setTime(19, 0),
        ]);
    }

    private function createGroupHotels(Tour $tour, int $paxCount): void
    {
        $totalNights = now()->parse($tour->departure_date)->diffInDays($tour->return_date);
        $hotelCount  = fake()->numberBetween(1, min(3, $totalNights));
        $roomCount   = (int) ceil($paxCount / 2);
        $currentDate = now()->parse($tour->departure_date);

        for ($i = 0; $i < $hotelCount; $i++) {
            $isLast = $i === $hotelCount - 1;
            $nights = $isLast
                ? now()->parse($tour->return_date)->diffInDays($currentDate)
                : fake()->numberBetween(1, (int) floor($totalNights / $hotelCount));

            $checkOut          = $currentDate->copy()->addDays($nights);
            $costPricePerNight = fake()->numberBetween(1500, 8000);

            TourHotel::factory()->create([
                'tour_id'              => $tour->id,
                'check_in_date'        => $currentDate->format('Y-m-d'),
                'check_out_date'       => $checkOut->format('Y-m-d'),
                'nights'               => $nights,
                'number_of_rooms'      => $roomCount,
                'cost_price_per_night' => $costPricePerNight,
                'total_cost_price'     => $costPricePerNight * $nights * $roomCount,
            ]);

            $currentDate = $checkOut;
        }
    }
}

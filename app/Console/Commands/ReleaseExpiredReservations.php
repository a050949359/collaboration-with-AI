<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Travel\Booking;
use Illuminate\Console\Command;

class ReleaseExpiredReservations extends Command
{
    protected $signature   = 'bookings:release-expired';
    protected $description = '將超過 15 分鐘未付款的 Reserved 訂單改為 Cancelled';

    public function handle(): void
    {
        $count = Booking::where('status', BookingStatus::Reserved->value)
            ->where('created_at', '<', now()->subMinutes(15))
            ->update(['status' => BookingStatus::Cancelled->value]);

        if ($count > 0) {
            $this->info("Released {$count} expired reservation(s).");
        }
    }
}

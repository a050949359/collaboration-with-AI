<?php

namespace App\Services\Export;

use App\Models\Export\ExportTask;
use App\Models\Travel\Tour;
use App\Services\Export\Contracts\ExportServiceInterface;
use Illuminate\Support\Facades\Storage;


class TourExportService implements ExportServiceInterface
{
    public function execute(array $params, int $exportId): void
    {
        if (empty($params['tour_id'])) {
            throw new \InvalidArgumentException('tour_id is required');
        }

        if (empty($params['tour_code'])) {
            throw new \InvalidArgumentException('tour_code is required');
        }

        $tourCode = $params['tour_code'];
        $path = "exports/export-{$tourCode}-{$exportId}.csv";
        $fullPath = storage_path("app/private/{$path}");

        Storage::disk('private')->makeDirectory(dirname($path));

        $file = fopen($fullPath, 'w');
        fputcsv($file, ['tour_code', 'tour_name', 'type', 'departure_date', 'booking_ref', 'passenger_name', 'travelers', 'status', 'final_amount', 'paid']);

        // 4. 分批從 DB 讀取，逐筆寫入（lazy 避免 OOM）
        Tour::with(['bookings.passenger', 'bookings.payments'])
            ->where('id', $params['tour_id'])
            ->lazy(500)
            ->each(function (Tour $tour) use ($file) {
                foreach ($tour->bookings as $booking) {
                    fputcsv($file, [
                        $tour->code,
                        $tour->name,
                        $tour->type->value,
                        $tour->departure_date->format('Y-m-d'),
                        $booking->booking_reference,
                        $booking->passenger->name,
                        $booking->number_of_travelers,
                        $booking->status->value,
                        $booking->final_amount,
                        $booking->payments->sum('amount'),
                    ]);
                }
            });

        fclose($file);

        // 5. 更新 DB + Redis cache
        ExportTask::find($exportId)?->update([
            'status'    => 'completed',
            'file_path' => $path,
        ]);
    }
}
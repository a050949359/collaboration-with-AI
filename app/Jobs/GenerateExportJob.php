<?php

namespace App\Jobs;

use App\Models\Export\ExportRequest;
use App\Models\TravelOrder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $exportId)
    {

    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Cache::put("export:{$this->exportId}:status", 'processing', 300);

        $path = "exports/export-{$this->exportId}.csv";
        $fullPath = storage_path("app/private/{$path}");

        Storage::disk('private')->makeDirectory(dirname($path));

        $file = fopen($fullPath, 'w');
        fputcsv($file, ['ID', 'Passenger', 'Origin', 'Destination', 'Date', 'Amount', 'Status']);

        // 4. 分批從 DB 讀取，逐筆寫入（lazy 避免 OOM）
        TravelOrder::query()
            ->orderBy('id')
            ->lazy(1000)
            ->each(function (TravelOrder $order) use ($file) {
                fputcsv($file, [
                    $order->id,
                    $order->passenger_name,
                    $order->origin,
                    $order->destination,
                    $order->departure_date,
                    $order->amount,
                    $order->status,
                ]);
            });

        fclose($file);

        // 5. 更新 DB + Redis cache
        ExportRequest::find($this->exportId)?->update([
            'status'    => 'completed',
            'file_path' => $path,
        ]);

        Cache::put("export:{$this->exportId}:status", 'completed', 300);
    }

    public function failed(Throwable $e): void
    {
        ExportRequest::find($this->exportId)?->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
        ]);

        Cache::put("export:{$this->exportId}:status", 'failed', 300);
    }
}

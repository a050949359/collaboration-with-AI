<?php

namespace App\Jobs;

use App\Models\Export\ExportTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class GenerateExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $serviceClass,
        public array $params,
        public int $exportId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Cache::put("export:{$this->exportId}:status", 'processing', 300);

        $service = app($this->serviceClass);

        $service->execute($this->params, $this->exportId);

        Cache::put("export:{$this->exportId}:status", 'completed', 300);
    }

    public function failed(Throwable $e): void
    {
        ExportTask::find($this->exportId)?->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
        ]);

        Cache::put("export:{$this->exportId}:status", 'failed', 300);
    }
}

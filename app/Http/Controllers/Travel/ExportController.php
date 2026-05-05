<?php

namespace App\Http\Controllers\Travel;

use App\Enums\ExportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Travel\ExportRequest;
use App\Models\Export\ExportTask;
use App\Jobs\GenerateExportJob;
use App\Services\Export\TourExportService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ExportController extends Controller
{
    public function index() {
        $exportTasks = ExportTask::orderBy('created_at', 'desc')->get();

        return response()->json($exportTasks);
    }

    public function store(ExportRequest $request) {
        $validated = $request->validated();

        $params = ['tour_id' => $validated['tour_id'], 'tour_code' => $validated['tour_code']];

        $exportTask = ExportTask::create([
            'params' => json_encode($params),
        ]);

        GenerateExportJob::dispatch(TourExportService::class, $params, $exportTask->id);

        return response()->json(['id' => $exportTask->id], 201);
    }

    public function status($id) {
        $status = Cache::get("export:{$id}:status");

        if (!$status) {
            $exportTask = ExportTask::find($id);
            if (!$exportTask) {
                return response()->json(['error' => 'Export task not found'], 404);
            }
            $status = $exportTask->status;
        }

        return response()->json(['status' => $status]);
    }

    public function download($id) {
        $exportTask = ExportTask::find($id);

        if (!$exportTask) {
            return response()->json(['error' => 'Export task not found'], 404);
        }

        if ($exportTask->status !== ExportStatus::COMPLETED->value) {
            return response()->json(['error' => 'Export not ready for download'], 400);
        }

        $disk = Storage::disk('private');
        $filePath = (string) $exportTask->file_path;

        if (!$disk->exists($filePath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        return response()->download(storage_path("app/private/{$exportTask->file_path}"));
    }
}

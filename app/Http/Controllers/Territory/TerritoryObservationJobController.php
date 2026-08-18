<?php

namespace App\Http\Controllers\Territory;

use App\Enums\Territory\ObservationJobStatus;
use App\Http\Controllers\Controller;
use App\Models\Territory\TerritoryObservationJob;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TerritoryObservationJobController extends Controller
{
    use ApiResponse;

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'entity_name' => ['required', 'string', 'regex:/^Q\d+$/'],
        ]);

        $job = TerritoryObservationJob::queue($request->entity_name, $request->user()->id);

        return $this->success(['job_id' => $job->id], 'Observation refresh queued.', 202);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $job = TerritoryObservationJob::where('id', $id)
            ->where('submitted_by', $request->user()->id)
            ->firstOrFail();

        return $this->success([
            'id' => $job->id,
            'entity_name' => $job->entity_name,
            'status' => $job->status,
            'error' => $job->error,
            'created_at' => $job->created_at,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(ObservationJobStatus::class)],
        ]);

        $jobs = TerritoryObservationJob::where('submitted_by', $request->user()->id)
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->limit(50)
            ->get(['id', 'entity_name', 'status', 'error', 'created_at']);

        return $this->success($jobs);
    }
}

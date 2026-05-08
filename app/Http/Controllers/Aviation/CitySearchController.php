<?php

namespace App\Http\Controllers\Aviation;

use App\Http\Controllers\Controller;
use App\Jobs\SearchCityJob;
use App\Models\Aviation\CitySearchJob;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CitySearchController extends Controller
{
    use ApiResponse;

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'city_name'    => ['required', 'string', 'max:100'],
            'wikidata_qid' => ['required', 'string', 'regex:/^Q\d+$/'],
            'country_code' => ['required', 'string', 'size:2'],
        ]);

        $job = CitySearchJob::create([
            'city_name'    => $request->city_name,
            'wikidata_qid' => $request->wikidata_qid,
            'country_code' => strtoupper($request->country_code),
            'status'       => 'pending',
            'submitted_by' => $request->user()->id,
        ]);

        SearchCityJob::dispatch($job->id);

        return $this->success(['job_id' => $job->id], 202);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $job = CitySearchJob::where('id', $id)
            ->where('submitted_by', $request->user()->id)
            ->with('city')
            ->firstOrFail();

        return $this->success([
            'id'           => $job->id,
            'city_name'    => $job->city_name,
            'wikidata_qid' => $job->wikidata_qid,
            'country_code' => $job->country_code,
            'status'       => $job->status,
            'error'        => $job->error,
            'city'         => $job->city,
            'created_at'   => $job->created_at,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'country_code' => ['nullable', 'string', 'size:2'],
        ]);

        $jobs = CitySearchJob::where('submitted_by', $request->user()->id)
            ->when($request->country_code, fn($q) => $q->where('country_code', strtoupper($request->country_code)))
            ->with('city')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn($job) => [
                'id'           => $job->id,
                'city_name'    => $job->city_name,
                'wikidata_qid' => $job->wikidata_qid,
                'country_code' => $job->country_code,
                'status'       => $job->status,
                'error'        => $job->error,
                'city'         => $job->city,
                'created_at'   => $job->created_at,
            ]);

        return $this->success($jobs);
    }
}

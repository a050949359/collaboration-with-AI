<?php

namespace App\Jobs;

use App\Enums\Territory\ObservationJobStatus;
use App\Enums\Territory\SubdivisionObservationType;
use App\Models\Territory\TerritoryEntity;
use App\Models\Territory\TerritoryObservationJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

// 只服務行政區層（territory-import-subdivisions.py）。國家層的 observation 有一部分
// 不是 Wikidata 查得到的（recognized/status/notes 是本地判斷），不適用「伺服器自己
// 打 Wiki 補齊」這套，國家層既有資料改用手動 backfill 處理，不走這個 job。
class WriteTerritoryObservationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const SPARQL_ENDPOINT = 'https://query.wikidata.org/sparql';

    private const USER_AGENT = 'collaboration-with-AI/1.0 (haroldchen@besttour.com.tw)';

    public function __construct(public int $jobId) {}

    public function handle(): void
    {
        $job = TerritoryObservationJob::find($this->jobId);
        if (! $job) {
            return;
        }

        $job->update(['status' => ObservationJobStatus::Processing]);

        try {
            $entity = TerritoryEntity::where('name', $job->entity_name)->first();
            if (! $entity) {
                $job->update(['status' => ObservationJobStatus::Failed, 'error' => "Entity '{$job->entity_name}' not found"]);

                return;
            }

            $fields = $this->fetchFromWikidata($job->entity_name);
            if ($fields === null) {
                $job->update(['status' => ObservationJobStatus::Failed, 'error' => 'No data returned from Wikidata']);

                return;
            }

            // 整批包在同一個 transaction：job 執行到一半被殺掉不會留下半套的觀察資料。
            // 用 updateOrCreate（而非 delete+create）逐 type 單行原子操作，避免「刪除後、
            // 插入前」的空檔——雖然 TerritoryObservationJob::queue() 已經擋掉同一 entity
            // 被兩個 job 同時處理，這裡的原子性主要是防 job 執行到一半被殺掉留下半套資料。
            DB::transaction(function () use ($entity, $fields) {
                foreach ($fields as $type => $value) {
                    // 同一 entity 同一 type 只留最新值，讓這個 job 可以安全重跑（補資料/刷新資料）。
                    $entity->observations()->updateOrCreate(['type' => $type], ['content' => $value]);
                }
            });

            $job->update(['status' => ObservationJobStatus::Success]);
        } catch (Throwable $e) {
            $job->update(['status' => ObservationJobStatus::Failed, 'error' => $e->getMessage()]);
        }
    }

    public function failed(Throwable $exception): void
    {
        TerritoryObservationJob::where('id', $this->jobId)
            ->update(['status' => ObservationJobStatus::Failed, 'error' => $exception->getMessage()]);
    }

    /** @return array<string, string>|null type => value，空值欄位已濾除 */
    private function fetchFromWikidata(string $qid): ?array
    {
        $sparql = <<<SPARQL
SELECT ?label ?desc ?coords ?population ?area
       (GROUP_CONCAT(DISTINCT ?instanceOfLabel; separator=", ") AS ?instanceOfLabels)
WHERE {
  OPTIONAL { wd:{$qid} rdfs:label ?label . FILTER(LANG(?label) = "en") }
  OPTIONAL { wd:{$qid} schema:description ?desc . FILTER(LANG(?desc) = "en") }
  OPTIONAL { wd:{$qid} wdt:P625 ?coords }
  OPTIONAL { wd:{$qid} wdt:P1082 ?population }
  OPTIONAL { wd:{$qid} wdt:P2046 ?area }
  OPTIONAL {
    wd:{$qid} wdt:P31 ?instanceOf .
    ?instanceOf rdfs:label ?instanceOfLabel .
    FILTER(LANG(?instanceOfLabel) = "en")
  }
}
GROUP BY ?label ?desc ?coords ?population ?area
LIMIT 1
SPARQL;

        $response = Http::withHeaders([
            'Accept' => 'application/sparql-results+json',
            'User-Agent' => self::USER_AGENT,
        ])->timeout(20)->get(self::SPARQL_ENDPOINT, ['query' => $sparql, 'format' => 'json']);

        if (! $response->successful()) {
            return null;
        }

        $b = $response->json('results.bindings.0');
        if (empty($b)) {
            return null;
        }

        $fields = [
            SubdivisionObservationType::Label->value => $b['label']['value'] ?? null,
            SubdivisionObservationType::Description->value => $b['desc']['value'] ?? null,
            SubdivisionObservationType::InstanceOf->value => $b['instanceOfLabels']['value'] ?? null,
            SubdivisionObservationType::Coordinates->value => $b['coords']['value'] ?? null,
            SubdivisionObservationType::Population->value => $b['population']['value'] ?? null,
            SubdivisionObservationType::Area->value => $b['area']['value'] ?? null,
        ];

        return array_filter($fields, fn ($v) => $v !== null && $v !== '');
    }
}

<?php

namespace App\Jobs;

use App\Enums\Territory\CountryObservationType;
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

// 依 entity 的 type 分流：country 節點打國家版 SPARQL + 讀本機 countries 表補
// recognized/status/notes；其餘（行政區層）打行政區版 SPARQL。兩者都是伺服器自己
// 重新查一次 Wikidata，呼叫端（territory-import-*.py）只需要傳 entity_name 觸發。
class WriteTerritoryObservationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const SPARQL_ENDPOINT = 'https://query.wikidata.org/sparql';

    private const USER_AGENT = 'collaboration-with-AI/1.0 (haroldchen@besttour.com.tw)';

    // countries 表已退役、僅供這裡讀取 recognized/status/notes 這三個非 Wikidata 欄位；
    // 這份清單是唯一來源（原本 Python 端也有一份同款判斷，country 層改走這支 job 後
    // Python 那份已刪除，不用再兩邊同步）。
    private const DISSOLVED_CODES = ['DD', 'YU', 'AN', 'PC'];

    private const UNCLAIMED_CODES = ['AQ'];

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

            $fields = $entity->type === 'country'
                ? $this->fetchCountryFields($job->entity_name)
                : $this->fetchSubdivisionFields($job->entity_name);

            // empty()（非單純 === null）：bindings 存在但濾完全空值也視為「沒抓到資料」失敗，
            // 否則下面的 whereNotIn('type', []) 會變成 where 1=1，把這個 entity 的觀察資料全刪光。
            if (empty($fields)) {
                $job->update(['status' => ObservationJobStatus::Failed, 'error' => 'No data returned from Wikidata']);

                return;
            }

            // 整批包在同一個 transaction：job 執行到一半被殺掉不會留下半套的觀察資料。
            // 用 updateOrCreate（而非 delete+create）逐 type 單行原子操作，避免「刪除後、
            // 插入前」的空檔——雖然 TerritoryObservationJob::queue() 已經擋掉同一 entity
            // 被兩個 job 同時處理，這裡的原子性主要是防 job 執行到一半被殺掉留下半套資料。
            DB::transaction(function () use ($entity, $fields) {
                // 先清掉「這次 Wiki 沒抓到值」的舊 type：避免欄位在 Wikidata 上被移除後，
                // 舊的觀察資料一直殘留在這個 entity 底下變成過時資訊。
                $entity->observations()->whereNotIn('type', array_keys($fields))->delete();

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
    private function fetchSubdivisionFields(string $qid): ?array
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

        $b = $this->querySparql($sparql);
        if ($b === null) {
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

    /** @return array<string, string>|null type => value，空值欄位已濾除 */
    private function fetchCountryFields(string $qid): ?array
    {
        $sparql = <<<SPARQL
SELECT ?labelEn ?labelZhTw ?iso2 ?iso3 ?isoNum ?capitalLabel ?callingCode ?population ?continentLabel
WHERE {
  OPTIONAL { wd:{$qid} rdfs:label ?labelEn . FILTER(LANG(?labelEn) = "en") }
  OPTIONAL { wd:{$qid} rdfs:label ?labelZhTw . FILTER(LANG(?labelZhTw) = "zh-tw") }
  OPTIONAL { wd:{$qid} wdt:P297 ?iso2 }
  OPTIONAL { wd:{$qid} wdt:P298 ?iso3 }
  OPTIONAL { wd:{$qid} wdt:P299 ?isoNum }
  OPTIONAL { wd:{$qid} wdt:P36 ?capital }
  OPTIONAL { wd:{$qid} wdt:P474 ?callingCode }
  OPTIONAL { wd:{$qid} wdt:P1082 ?population }
  OPTIONAL { wd:{$qid} wdt:P30 ?continent }
  SERVICE wikibase:label { bd:serviceParam wikibase:language "en". }
}
LIMIT 1
SPARQL;

        $b = $this->querySparql($sparql);
        if ($b === null) {
            return null;
        }

        $fields = [
            CountryObservationType::LabelEn->value => $b['labelEn']['value'] ?? null,
            CountryObservationType::LabelZhTw->value => $b['labelZhTw']['value'] ?? null,
            CountryObservationType::IsoCode->value => $b['iso2']['value'] ?? null,
            CountryObservationType::Alpha3->value => $b['iso3']['value'] ?? null,
            CountryObservationType::Numeric->value => $b['isoNum']['value'] ?? null,
            CountryObservationType::Capital->value => $b['capitalLabel']['value'] ?? null,
            CountryObservationType::PhoneCode->value => $b['callingCode']['value'] ?? null,
            CountryObservationType::Population->value => $b['population']['value'] ?? null,
            CountryObservationType::Continent->value => $b['continentLabel']['value'] ?? null,
        ];

        // recognized/status/notes 不是 Wikidata 查得到的，來自本機已退役的 countries 表——
        // 用上面查到的 iso2 code 去對應那張表的一列，找不到就跳過這三個欄位，不影響其他欄位。
        $iso2 = $b['iso2']['value'] ?? null;
        if ($iso2) {
            $country = DB::table('countries')->where('code', $iso2)->first();
            if ($country) {
                $fields[CountryObservationType::Recognized->value] = $country->is_recognized ? 'yes' : 'no';
                $fields[CountryObservationType::Status->value] = $this->countryStatus($iso2, (bool) $country->is_recognized);
                $fields[CountryObservationType::Notes->value] = $country->notes;
            }
        }

        return array_filter($fields, fn ($v) => $v !== null && $v !== '');
    }

    // countries 表混了「現行主權國家」跟「依附其他國家的屬地／已解體的歷史政權」——
    // is_recognized 只分兩類（yes/no），但 no 底下其實還有兩種完全不同的東西：
    //   1. 依附其他國家的屬地（香港、Guam、格陵蘭…）：這些之後會在 territory-import-
    //      subdivisions.py 掃到它們真正的宗主國時，被 agy 判斷成正確的行政區 type、
    //      掛上 part_of 關係——但因為 create_entity 是 firstOrCreate，已存在的 entity
    //      type 不會被覆寫，所以這裡先補一個 status observation 說明它「其實是
    //      dependency」，跟 type 欄位分開。
    //   2. 真正已解體、不再屬於任何現行國家的歷史政權：不會被任何國家的 P150 掃到，
    //      需要單獨標記，否則會一直頂著誤導性的 status。
    private function countryStatus(string $code, bool $isRecognized): string
    {
        if (\in_array($code, self::DISSOLVED_CODES, true)) {
            return 'dissolved';
        }
        if (\in_array($code, self::UNCLAIMED_CODES, true)) {
            return 'unclaimed';
        }

        return $isRecognized ? 'sovereign' : 'dependency';
    }

    /** @return array<string, mixed>|null 第一筆 binding，查詢失敗或無結果回傳 null */
    private function querySparql(string $sparql): ?array
    {
        $response = Http::withHeaders([
            'Accept' => 'application/sparql-results+json',
            'User-Agent' => self::USER_AGENT,
        ])->timeout(20)->get(self::SPARQL_ENDPOINT, ['query' => $sparql, 'format' => 'json']);

        if (! $response->successful()) {
            return null;
        }

        $b = $response->json('results.bindings.0');

        return empty($b) ? null : $b;
    }
}

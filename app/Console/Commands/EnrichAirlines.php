<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class EnrichAirlines extends Command
{
    protected $signature = 'airlines:enrich {--dry-run : 只顯示統計，不寫入資料庫}';
    protected $description = '從 Wikidata 補全航空公司中文名稱，並新增 DB 缺少的航空公司';

    private const SPARQL_URL = 'https://query.wikidata.org/sparql';
    private const SPARQL     = <<<'SPARQL'
SELECT ?iata ?icao ?nameEn ?nameZhTw ?nameZh WHERE {
  ?airline wdt:P229 ?iata .
  OPTIONAL { ?airline wdt:P230 ?icao }
  OPTIONAL { ?airline rdfs:label ?nameEn   FILTER(lang(?nameEn)   = "en") }
  OPTIONAL { ?airline rdfs:label ?nameZhTw FILTER(lang(?nameZhTw) = "zh-tw") }
  OPTIONAL { ?airline rdfs:label ?nameZh   FILTER(lang(?nameZh)   = "zh") }
}
SPARQL;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->line('從 Wikidata 抓取航空公司資料...');
        $wikidata = $this->fetchWikidata();
        if ($wikidata === null) {
            return self::FAILURE;
        }
        $this->info('Wikidata 取得 ' . count($wikidata) . ' 筆（by IATA）');

        // ── Phase 1：補現有記錄的中文名 ──────────────────────────
        $existing = DB::table('airlines')
            ->whereNotNull('iata')
            ->where(fn($q) => $q->whereNull('name_zh_tw')->orWhere('name_zh_tw', ''))
            ->get(['id', 'iata']);

        $updates = [];
        foreach ($existing as $airline) {
            $row  = $wikidata[$airline->iata] ?? null;
            $name = $row['name_zh_tw'] ?? $row['name_zh'] ?? null;
            if ($name) {
                $updates[$airline->id] = $name;
            }
        }

        $this->info('可補 name_zh_tw：' . count($updates) . ' 筆');

        // ── Phase 2：新增 DB 沒有的航空公司 ─────────────────────
        $dbIata = DB::table('airlines')->whereNotNull('iata')->pluck('iata')
            ->map(fn($v) => strtoupper($v))->flip();

        $toInsert = [];
        foreach ($wikidata as $iata => $row) {
            if ($dbIata->has(strtoupper($iata))) {
                continue;
            }
            if (! ($row['name_en'] ?? null)) {
                continue;   // 沒有英文名，略過
            }
            $toInsert[] = [
                'iata'       => $iata,
                'icao'       => $row['icao'] ?? null,
                'name_en'    => $row['name_en'],
                'name_zh_tw' => $row['name_zh_tw'] ?? $row['name_zh'] ?? null,
            ];
        }

        $this->info('可新增航空公司：' . count($toInsert) . ' 筆');

        if ($dryRun) {
            if ($toInsert) {
                $this->line('新增範例（前5筆）：');
                foreach (array_slice($toInsert, 0, 5) as $r) {
                    $this->line("  {$r['iata']} / " . ($r['icao'] ?? '--') . "  {$r['name_en']}  " . ($r['name_zh_tw'] ?? ''));
                }
            }
            $this->warn('--dry-run 模式，跳過寫入');
            return self::SUCCESS;
        }

        // ── 寫入 ────────────────────────────────────────────────
        $this->line('寫入 name_zh_tw...');
        foreach ($updates as $id => $name) {
            DB::table('airlines')->where('id', $id)->update(['name_zh_tw' => $name]);
        }
        $this->info('更新完成：' . count($updates) . ' 筆');

        if ($toInsert) {
            $this->line('新增航空公司...');
            $now = now();
            $rows = array_map(fn($r) => array_merge($r, [
                'created_at' => $now,
                'updated_at' => $now,
            ]), $toInsert);

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('airlines')->insertOrIgnore($chunk);
            }
            $this->info('新增完成：' . count($toInsert) . ' 筆');
        }

        $this->info('完成！');
        return self::SUCCESS;
    }

    private function fetchWikidata(): ?array
    {
        $response = Http::withHeaders([
            'Accept'     => 'application/sparql-results+json',
            'User-Agent' => 'BesttourApp/1.0 (haroldchen@besttour.com.tw)',
        ])
            ->timeout(90)
            ->get(self::SPARQL_URL, ['query' => self::SPARQL]);

        if (! $response->successful()) {
            $this->error("Wikidata 請求失敗（HTTP {$response->status()}）：" . $response->body());
            return null;
        }

        $result = [];

        foreach ($response->json('results.bindings') ?? [] as $b) {
            $iata = $b['iata']['value'] ?? null;
            if (! $iata) {
                continue;
            }

            if (! isset($result[$iata])) {
                $result[$iata] = [
                    'icao'       => $b['icao']['value']      ?? null,
                    'name_en'    => $b['nameEn']['value']    ?? null,
                    'name_zh_tw' => $b['nameZhTw']['value']  ?? null,
                    'name_zh'    => $b['nameZh']['value']    ?? null,
                ];
            } else {
                foreach (['icao', 'name_en', 'name_zh_tw', 'name_zh'] as $field) {
                    $key = match($field) {
                        'icao'       => 'icao',
                        'name_en'    => 'nameEn',
                        'name_zh_tw' => 'nameZhTw',
                        'name_zh'    => 'nameZh',
                    };
                    if (! $result[$iata][$field] && isset($b[$key])) {
                        $result[$iata][$field] = $b[$key]['value'];
                    }
                }
            }
        }

        return $result;
    }
}

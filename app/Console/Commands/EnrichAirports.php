<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class EnrichAirports extends Command
{
    protected $signature = 'airports:enrich {--dry-run : 只顯示統計，不寫入資料庫}';
    protected $description = '從 Wikidata 補全中大型機場的中文名、IATA、ICAO 代碼';

    private const SPARQL_URL = 'https://query.wikidata.org/sparql';
    private const TYPES      = ['large_airport', 'medium_airport'];
    private const SPARQL     = <<<'SPARQL'
SELECT ?iata ?icao ?nameEn ?nameZhTw ?nameZh WHERE {
  ?airport wdt:P238 ?iata .
  OPTIONAL { ?airport wdt:P239 ?icao }
  OPTIONAL { ?airport rdfs:label ?nameEn   FILTER(lang(?nameEn)   = "en") }
  OPTIONAL { ?airport rdfs:label ?nameZhTw FILTER(lang(?nameZhTw) = "zh-tw") }
  OPTIONAL { ?airport rdfs:label ?nameZh   FILTER(lang(?nameZh)   = "zh") }
}
SPARQL;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // ── 1. 從 Wikidata 抓資料 ────────────────────────────────
        $this->line('從 Wikidata 抓取機場資料...');
        $wikidata = $this->fetchWikidata();
        if ($wikidata === null) {
            return self::FAILURE;
        }
        $this->info('Wikidata 取得 ' . count($wikidata) . ' 筆（by IATA）');

        // ── 2. 取得本地中大型機場 ────────────────────────────────
        $airports = DB::table('airports')
            ->whereIn('type', self::TYPES)
            ->get(['id', 'iata_code', 'icao_code', 'name', 'name_zh_tw']);

        $this->line('本地中大型機場：' . $airports->count() . ' 筆');

        // ── 3. 建立 ICAO → wikidata row 的索引（for fallback）──
        $byIcao = collect($wikidata)->keyBy('icao')->filter(fn($r) => $r['icao']);

        // ── 4. 逐筆比對並累計更新 ────────────────────────────────
        $stats = ['name_zh_tw' => 0, 'iata' => 0, 'icao' => 0];
        $updates = [];

        foreach ($airports as $airport) {
            $row = null;

            if ($airport->iata_code) {
                $row = $wikidata[$airport->iata_code] ?? null;
            }

            if (! $row && $airport->icao_code) {
                $row = $byIcao[$airport->icao_code] ?? null;
            }

            if (! $row) {
                continue;
            }

            $update = [];

            if (! $airport->name_zh_tw) {
                $zhTw = $row['name_zh_tw'] ?? $row['name_zh'] ?? null;
                if ($zhTw) {
                    $update['name_zh_tw'] = $zhTw;
                    $stats['name_zh_tw']++;
                }
            }

            if (! $airport->iata_code && ($row['iata'] ?? null)) {
                $update['iata_code'] = $row['iata'];
                $stats['iata']++;
            }

            if (! $airport->icao_code && ($row['icao'] ?? null)) {
                $update['icao_code'] = $row['icao'];
                $stats['icao']++;
            }

            if ($update) {
                $updates[$airport->id] = $update;
            }
        }

        // ── 5. 批次寫入 ──────────────────────────────────────────
        $this->info('');
        $this->info("name_zh_tw 可補：{$stats['name_zh_tw']} 筆");
        $this->info("iata_code  可補：{$stats['iata']} 筆");
        $this->info("icao_code  可補：{$stats['icao']} 筆");

        if ($dryRun) {
            $this->warn('--dry-run 模式，跳過寫入');
            return self::SUCCESS;
        }

        $this->line('');
        $this->line('寫入資料庫...');
        $bar = $this->output->createProgressBar(count($updates));
        $bar->start();

        foreach ($updates as $id => $update) {
            DB::table('airports')->where('id', $id)->update($update);
            $bar->advance();
        }

        $bar->finish();
        $this->line('');
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

        $bindings = $response->json('results.bindings') ?? [];
        $result   = [];

        foreach ($bindings as $b) {
            $iata = $b['iata']['value'] ?? null;
            if (! $iata) {
                continue;
            }

            // 同一 IATA 可能有多筆（多語言 label 各一行），合併取值
            if (! isset($result[$iata])) {
                $result[$iata] = [
                    'iata'      => $iata,
                    'icao'      => $b['icao']['value']      ?? null,
                    'name_en'   => $b['nameEn']['value']    ?? null,
                    'name_zh_tw' => $b['nameZhTw']['value'] ?? null,
                    'name_zh'   => $b['nameZh']['value']    ?? null,
                ];
            } else {
                // 補缺漏欄位
                if (! $result[$iata]['icao'] && isset($b['icao'])) {
                    $result[$iata]['icao'] = $b['icao']['value'];
                }
                if (! $result[$iata]['name_zh_tw'] && isset($b['nameZhTw'])) {
                    $result[$iata]['name_zh_tw'] = $b['nameZhTw']['value'];
                }
                if (! $result[$iata]['name_zh'] && isset($b['nameZh'])) {
                    $result[$iata]['name_zh'] = $b['nameZh']['value'];
                }
            }
        }

        return $result;
    }
}

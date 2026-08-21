<?php

namespace App\Enums\Territory;

/**
 * 國家層 territory_observations.type 的合法值。
 *
 * ⚠️ 這幾個 key 目前只是 territory-import-countries.py 的 build_observations()
 * 拿來組 `"key: value"` 字串塞進 content 用的慣例，**還沒接到這裡的 type 欄位**——
 * add_observation 目前不接受 type 參數，一律落在 model 預設值 type='desc'。
 * 也就是說 `TerritoryObservation::where('type', 'iso_code')` 目前查不到任何國家資料，
 * 要等手動 backfill（把 content 的 "key: value" 拆成 type+content）跑完才會對得上。
 * 這是刻意的：這次改動只處理行政區層，國家層維持原樣，這個 enum 先定義合法值待用。
 *
 * 前 7 個（LabelEn ~ PhoneCode）對應 Wikidata property 於下方註記——
 * 用 Taiwan(Q865)/US(Q30)/Nauru(Q697，小國代表)/Seychelles(Q1042)/Senegal(Q1041)
 * 五個差異很大的樣本實測過，這幾個 property 涵蓋率 100%，可視為穩定可取得。
 * Population/Continent 是同一輪實測中額外發現、同樣穩定可取得、但目前腳本還沒接上的候選欄位。
 * Recognized/Status/Notes 不是 Wikidata 查詢結果，是本地 countries 表欄位或程式判斷（見
 * territory_lib.country_status()），純粹路線不同，不代表可信度較低。
 */
enum CountryObservationType: string
{
    case LabelEn = 'label_en';       // rdfs:label（英文）
    case LabelZhTw = 'label_zh_tw';   // rdfs:label（zh-tw）
    case IsoCode = 'iso_code';       // wdt:P297，ISO 3166-1 alpha-2
    case Alpha3 = 'alpha3';         // wdt:P298
    case Numeric = 'numeric';        // wdt:P299
    case Capital = 'capital';        // wdt:P36
    case PhoneCode = 'phone_code';    // wdt:P474

    case Population = 'population';    // wdt:P1082（尚未接上腳本）
    case Continent = 'continent';      // wdt:P30（尚未接上腳本）

    case Recognized = 'recognized';    // 本地判斷：是否為現行主權國家
    case Status = 'status';         // 本地判斷：sovereign/dependency/dissolved/unclaimed
    case Notes = 'notes';          // 本地 countries 表欄位，自由文字

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

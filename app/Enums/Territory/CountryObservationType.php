<?php

namespace App\Enums\Territory;

/**
 * 國家層 territory_observations.type 的合法值。
 *
 * 全部都已接上：`App\Jobs\WriteTerritoryObservationJob::fetchCountryFields()`
 * 在伺服器端對 entity_name 重新打一次 Wikidata SPARQL（label_en/label_zh_tw/
 * iso_code/alpha3/numeric/capital/phone_code/population/continent），並用查到的
 * iso_code 去比對伺服器本機同一份 countries 表補上 recognized/status/notes
 * （status 判斷邏輯是 fetchCountryFields() 旁邊的 countryStatus()，純 PHP，不再
 * 依賴任何 Python 腳本）。Python 端（territory-import-countries.py）只呼叫
 * `refresh_observations(entity_name)` 觸發，不組 content、不接觸這些欄位。
 *
 * 前 7 個（LabelEn ~ PhoneCode）+ Population/Continent 的 Wikidata property 對應見下方
 * 註記——用 Taiwan(Q865)/US(Q30)/Nauru(Q697，小國代表)/Seychelles(Q1042)/Senegal(Q1041)
 * 五個差異很大的樣本實測過，這幾個 property 涵蓋率 100%，可視為穩定可取得。
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
    case Population = 'population';    // wdt:P1082
    case Continent = 'continent';      // wdt:P30

    case Recognized = 'recognized';    // 非 Wikidata：伺服器本機 countries 表欄位
    case Status = 'status';         // 非 Wikidata：伺服器端 countryStatus() 判斷
    case Notes = 'notes';          // 非 Wikidata：伺服器本機 countries 表欄位

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

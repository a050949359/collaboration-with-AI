<?php

namespace App\Enums\Territory;

/**
 * 行政區（國家以下第一層）層 territory_observations.type 的合法值。
 *
 * 6 個全部都已接上：`App\Jobs\WriteTerritoryObservationJob::fetchSubdivisionFields()`
 * 在伺服器端對 entity_name 重新打一次 Wikidata SPARQL，一次撈完這 6 個欄位並依
 * type dedup 後寫入。Python 端（territory-import-subdivisions.py）不再組 content，
 * 只呼叫 `refresh_observations(entity_name)` 觸發，實際欄位由伺服器決定。
 * 實測涵蓋率不像國家層那麼平均，用 Taipei/California/Fujian（大型行政區）+
 * Seychelles 27 個 district（小型行政區）混合樣本測過：
 *   - Label/Description/InstanceOf：幾乎 100%（僅 2 個剛設立的人工島 district 缺）
 *   - Coordinates(P625)：~96%（27 個 Seychelles district 只有 2 個新設的沒有）
 *   - Area(P2046)：~55%（Seychelles district 這種細粒度行政區常缺）
 *   - Population(P1082)：偏低（Seychelles 27 個 district 只有 2 個有；但 Taipei/California/
 *     Fujian 這種省級/州級/大城市級都有）——population 在「行政區夠大」時才可靠，
 *     細粒度行政區常常沒有，寫入前一定要檢查 null 再決定要不要跳過。
 */
enum SubdivisionObservationType: string
{
    case Label = 'label';           // rdfs:label
    case Description = 'description';    // schema:description
    case InstanceOf = 'instance_of';     // wdt:P31（GROUP_CONCAT 多值）

    case Coordinates = 'coordinates';    // wdt:P625
    case Population = 'population';     // wdt:P1082，細粒度行政區常缺，null 會被過濾不寫入
    case Area = 'area';            // wdt:P2046，細粒度行政區常缺，null 會被過濾不寫入

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

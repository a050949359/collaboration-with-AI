<?php

namespace App\Enums\Territory;

/**
 * 行政區（國家以下第一層）層 territory_observations.type 的合法值。
 *
 * 前 3 個（Label ~ InstanceOf）已在 territory-import-subdivisions.py 的
 * build_observations() 使用。Coordinates/Population/Area 是這輪實測額外發現、
 * 目前腳本還沒接上的候選欄位，實測涵蓋率不像國家層那麼平均：
 * 用 Taipei/California/Fujian（大型行政區）+ Seychelles 27 個 district（小型行政區）
 * 混合樣本測過：
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

    case Coordinates = 'coordinates';    // wdt:P625（尚未接上腳本）
    case Population = 'population';     // wdt:P1082（尚未接上腳本，細粒度行政區常缺）
    case Area = 'area';            // wdt:P2046（尚未接上腳本，細粒度行政區常缺）

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

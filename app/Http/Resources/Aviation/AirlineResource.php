<?php

namespace App\Http\Resources\Aviation;

use App\Models\Aviation\Airline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 包裝 {@see Airline}，$this->* 經 JsonResource 魔術代理到底層 model。
 *
 * @property int $id
 * @property string|null $iata
 * @property string|null $icao
 * @property string|null $name_en
 * @property string|null $name_zh_tw
 * @property string|null $alias_en
 * @property string|null $alias_zh_tw
 * @property string|null $nationality
 */
class AirlineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'iata' => $this->iata,
            'icao' => $this->icao,
            'name_en' => $this->name_en,
            'name_zh_tw' => $this->name_zh_tw,
            'alias_en' => $this->alias_en,
            'alias_zh_tw' => $this->alias_zh_tw,
            'nationality' => $this->nationality,
        ];
    }
}

<?php

namespace App\Http\Resources\Aviation;

use App\Models\Aviation\City;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 包裝 {@see City}，$this->* 經 JsonResource 魔術代理到底層 model。
 *
 * @property string|null $name_en
 * @property string|null $name_zh_tw
 * @property int|null $population
 */
class CityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name_en' => $this->name_en,
            'name_zh_tw' => $this->name_zh_tw,
            'population' => $this->population,
        ];
    }
}

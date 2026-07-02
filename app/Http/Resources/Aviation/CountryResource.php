<?php

namespace App\Http\Resources\Aviation;

use App\Models\Aviation\Country;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 包裝 {@see Country}，$this->* 經 JsonResource 魔術代理到底層 model。
 *
 * @property string $code
 * @property string|null $alpha3
 * @property string|null $numeric
 * @property string|null $name_en
 * @property string|null $name_zh_tw
 * @property string|null $capital
 * @property string|null $phone_code
 * @property bool $is_recognized
 */
class CountryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'alpha3' => $this->alpha3,
            'numeric' => $this->numeric,
            'name_en' => $this->name_en,
            'name_zh_tw' => $this->name_zh_tw,
            'capital' => $this->capital,
            'phone_code' => $this->phone_code,
            'is_recognized' => (bool) $this->is_recognized,
        ];
    }
}

<?php

namespace App\Http\Resources\Aviation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name_en'    => $this->name_en,
            'name_zh_tw' => $this->name_zh_tw,
            'population' => $this->population,
        ];
    }
}

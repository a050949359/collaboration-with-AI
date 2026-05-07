<?php

namespace App\Http\Resources\Aviation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CountryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code'          => $this->code,
            'alpha3'        => $this->alpha3,
            'numeric'       => $this->numeric,
            'name_en'       => $this->name_en,
            'name_zh_tw'    => $this->name_zh_tw,
            'capital'       => $this->capital,
            'phone_code'    => $this->phone_code,
            'is_recognized' => (bool) $this->is_recognized,
        ];
    }
}

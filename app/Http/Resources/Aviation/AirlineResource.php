<?php

namespace App\Http\Resources\Aviation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AirlineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'iata'        => $this->iata,
            'icao'        => $this->icao,
            'name_en'     => $this->name_en,
            'name_zh_tw'  => $this->name_zh_tw,
            'alias_en'    => $this->alias_en,
            'alias_zh_tw' => $this->alias_zh_tw,
            'nationality' => $this->nationality,
        ];
    }
}

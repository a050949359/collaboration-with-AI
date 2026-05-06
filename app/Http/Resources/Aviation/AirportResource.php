<?php

namespace App\Http\Resources\Aviation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AirportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'ident'              => $this->ident,
            'type'               => $this->type,
            'name'               => $this->name_zh_tw ?? $this->name,
            'location'           => [
                'latitude'     => $this->latitude_deg,
                'longitude'    => $this->longitude_deg,
                'elevation_ft' => $this->elevation_ft,
                'municipality' => $this->municipality,
                'region'       => $this->iso_region,
                'country'      => $this->iso_country,
                'continent'    => $this->continent,
            ],
            'codes'              => [
                'iata' => $this->iata_code,
                'icao' => $this->icao_code,
                'gps'  => $this->gps_code,
            ],
            'scheduled_service'  => $this->scheduled_service,
            'links'              => [
                'home'      => $this->home_link,
                'wikipedia' => $this->wikipedia_link,
            ],
            'distance_km' => $this->when(
                isset($this->distance_km),
                fn() => round($this->distance_km, 1)
            ),
        ];
    }
}

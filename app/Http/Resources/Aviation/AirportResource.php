<?php

namespace App\Http\Resources\Aviation;

use App\Models\Aviation\Airports;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 包裝 {@see Airports}，$this->* 經 JsonResource 魔術代理到底層 model。
 *
 * @property int $id
 * @property string $ident
 * @property string $type
 * @property string $name
 * @property string|null $name_zh_tw
 * @property float|null $latitude_deg
 * @property float|null $longitude_deg
 * @property int|null $elevation_ft
 * @property string|null $municipality
 * @property string|null $iso_region
 * @property string|null $iso_country
 * @property string|null $continent
 * @property string|null $iata_code
 * @property string|null $icao_code
 * @property string|null $gps_code
 * @property bool $scheduled_service
 * @property string|null $home_link
 * @property string|null $wikipedia_link
 * @property float|null $distance_km 由查詢 selectRaw 計算而來，非 DB 欄位
 */
class AirportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ident' => $this->ident,
            'type' => $this->type,
            'name' => $this->name_zh_tw ?? $this->name,
            'location' => [
                'latitude' => $this->latitude_deg,
                'longitude' => $this->longitude_deg,
                'elevation_ft' => $this->elevation_ft,
                'municipality' => $this->municipality,
                'region' => $this->iso_region,
                'country' => $this->iso_country,
                'continent' => $this->continent,
            ],
            'codes' => [
                'iata' => $this->iata_code,
                'icao' => $this->icao_code,
                'gps' => $this->gps_code,
            ],
            'scheduled_service' => $this->scheduled_service,
            'links' => [
                'home' => $this->home_link,
                'wikipedia' => $this->wikipedia_link,
            ],
            'distance_km' => $this->when(
                isset($this->distance_km),
                fn () => round($this->distance_km, 1)
            ),
        ];
    }
}

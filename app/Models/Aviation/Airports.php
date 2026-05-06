<?php

namespace App\Models\Aviation;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Airports extends Model
{
    protected $fillable = [
        'ident', 'type', 'name', 'name_zh_tw', 'latitude_deg', 'longitude_deg',
        'elevation_ft', 'continent', 'iso_country', 'iso_region',
        'municipality', 'scheduled_service', 'icao_code', 'iata_code',
        'gps_code', 'local_code', 'home_link', 'wikipedia_link', 'keywords',
    ];

    protected $casts = [
        'latitude_deg'      => 'float',
        'longitude_deg'     => 'float',
        'elevation_ft'      => 'integer',
        'scheduled_service' => 'boolean',
    ];

    // ── Query Scopes ──────────────────────────────

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $query->when($filters['search'] ?? null, fn($q, $s) =>
            $q->where(fn($q) =>
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('name_zh_tw', 'like', "%{$s}%")
                  ->orWhere('municipality', 'like', "%{$s}%")
                  ->orWhere('iata_code', $s)
                  ->orWhere('icao_code', $s)
                  ->orWhere('ident', $s)
            )
        );

        $query->when($filters['type'] ?? null, fn($q, $t) =>
            $q->whereIn('type', (array) $t)
        );

        $query->when($filters['continent'] ?? null, fn($q, $c) =>
            $q->whereIn('continent', array_map('strtoupper', (array) $c))
        );

        $query->when($filters['country'] ?? null, fn($q, $c) =>
            $q->where('iso_country', strtoupper($c))
        );

        $query->when($filters['region'] ?? null, fn($q, $r) =>
            $q->where('iso_region', strtoupper($r))
        );

        $query->when(isset($filters['scheduled']), fn($q) =>
            $q->where('scheduled_service', filter_var($filters['scheduled'], FILTER_VALIDATE_BOOLEAN))
        );

        return $query;
    }

    // 附近機場（Haversine 公式）
    public function scopeNearby(Builder $query, float $lat, float $lng, float $radiusKm = 100): Builder
    {
        $haversine = "(
            6371 * ACOS(
                COS(RADIANS(?)) * COS(RADIANS(latitude_deg)) *
                COS(RADIANS(longitude_deg) - RADIANS(?)) +
                SIN(RADIANS(?)) * SIN(RADIANS(latitude_deg))
            )
        )";

        return $query
            ->selectRaw("*, {$haversine} AS distance_km", [$lat, $lng, $lat])
            ->whereNotNull('latitude_deg')
            ->whereNotNull('longitude_deg')
            ->having('distance_km', '<=', $radiusKm)
            ->orderBy('distance_km');
    }
}

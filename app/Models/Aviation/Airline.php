<?php

namespace App\Models\Aviation;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Airline extends Model
{
    protected $fillable = [
        'iata', 'icao', 'name_en', 'name_zh_tw', 'alias_en', 'alias_zh_tw', 'nationality',
    ];

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $query->when($filters['search'] ?? null, fn($q, $s) =>
            $q->where(fn($q) =>
                $q->where('name_en', 'like', "%{$s}%")
                  ->orWhere('name_zh_tw', 'like', "%{$s}%")
                  ->orWhere('iata', strtoupper($s))
                  ->orWhere('icao', strtoupper($s))
            )
        );

        $query->when($filters['nationality'] ?? null, fn($q, $n) =>
            $q->where('nationality', $n)
        );

        return $query;
    }
}

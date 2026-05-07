<?php

namespace App\Models\Aviation;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $fillable = [
        'code',
        'alpha3',
        'numeric',
        'name_en',
        'name_zh_tw',
        'name_zh',
        'capital',
        'phone_code',
        'parent_code',
        'notes',
        'is_recognized',
    ];

    protected $casts = [
        'is_recognized' => 'boolean',
    ];

    public function scopeFilter(\Illuminate\Database\Eloquent\Builder $q, array $filters): \Illuminate\Database\Eloquent\Builder
    {
        if ($s = $filters['search'] ?? null) {
            $s = strtoupper($s);
            $q->where(fn($q) =>
                $q->where('name_en', 'like', "%{$s}%")
                  ->orWhere('name_zh_tw', 'like', "%{$s}%")
                  ->orWhere('code', $s)
                  ->orWhere('alpha3', $s)
            );
        }

        if (isset($filters['recognized'])) {
            $q->where('is_recognized', (bool) $filters['recognized']);
        }

        return $q;
    }

    public function cities(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(City::class, 'country_code', 'code');
    }
}

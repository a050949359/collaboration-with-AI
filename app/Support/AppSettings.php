<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * 後台 System 設定的單一讀取入口。
 *
 * 設定由 Admin\SettingsController 寫入 Redis cache key `admin_settings`，
 * 此類別負責「讀取 + 套用預設值 + Redis 掛掉時退回預設」，
 * 讓 middleware / controller / Inertia 共享同一份來源，不各自硬編 key 與預設。
 */
class AppSettings
{
    public const CACHE_KEY = 'admin_settings';

    /**
     * 完整設定（cached 值覆蓋預設值）。Redis 不可用時 rescue 退回純預設。
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $cached = rescue(fn () => Cache::get(self::CACHE_KEY), null) ?? [];

        return array_merge(self::defaults(), $cached);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return (bool) self::get($key, $default);
    }

    /**
     * 各設定的預設值（單一來源）。
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'site_name'          => config('app.name'),
            'maintenance_mode'   => false,
            'allow_registration' => true,
            'max_login_attempts' => 5,
            'avatar_size'        => 128,
            'llm'                => config('services.llm.uses', []),
        ];
    }
}

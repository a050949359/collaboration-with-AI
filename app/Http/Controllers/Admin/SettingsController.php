<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    private const SETTINGS_CACHE_KEY = 'admin_settings';

    private const DEFAULTS = [
        'site_name'          => 'BINARY_EDITORIAL',
        'maintenance_mode'   => false,
        'allow_registration' => true,
        'max_login_attempts' => 5,
        'avatar_size'        => 128,
    ];

    public function index(): Response
    {
        return Inertia::render('Admin/Settings');
    }

    public function show(): JsonResponse
    {
        return response()->json($this->getSettings());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_name'          => ['sometimes', 'string', 'max:80'],
            'maintenance_mode'   => ['sometimes', 'boolean'],
            'allow_registration' => ['sometimes', 'boolean'],
            'max_login_attempts' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'avatar_size'        => ['sometimes', 'integer', 'in:64,128,256'],
        ]);

        $current = $this->getSettings();
        $merged = array_merge($current, $validated);

        Cache::forever(self::SETTINGS_CACHE_KEY, $merged);

        return response()->json([
            'message'  => '設定已更新',
            'settings' => $merged,
        ]);
    }

    private function getSettings(): array
    {
        return Cache::get(self::SETTINGS_CACHE_KEY, self::DEFAULTS);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Redis;

class MicroHostController extends Controller
{
    public function status(): JsonResponse
    {
        try {
            $raw = Redis::get(config('micro-host.ttl_key'));
        } catch (\Throwable) {
            return response()->json(['status' => 'offline', 'error' => 'redis_unavailable']);
        }

        if (! $raw) {
            return response()->json(['status' => 'offline']);
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return response()->json(['status' => 'offline', 'error' => 'invalid_payload']);
        }

        return response()->json([
            'status'    => 'online',
            'host'      => $data['host'] ?? null,
            'last_seen' => $data['last_seen'] ?? null,
            'api_error' => $data['api_error'] ?? null,
            'vms'       => is_array($data['vms'] ?? null) ? $data['vms'] : [],
            'cts'       => is_array($data['cts'] ?? null) ? $data['cts'] : [],
        ]);
    }
}

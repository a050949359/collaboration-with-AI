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

        return response()->json([
            ...json_decode($raw, true),
            'status' => 'online',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Redis;

class MicroHostController extends Controller
{
    public function status(): JsonResponse
    {
        $raw = Redis::get(config('micro-host.ttl_key'));

        if (! $raw) {
            return response()->json(['status' => 'offline']);
        }

        return response()->json(array_merge(
            json_decode($raw, true),
            ['status' => 'online']
        ));
    }
}

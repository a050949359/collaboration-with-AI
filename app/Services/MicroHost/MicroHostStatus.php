<?php

namespace App\Services\MicroHost;

use Illuminate\Support\Facades\Redis;

/**
 * 讀取微型主機（Proxmox）心跳。
 *
 * 心跳由 remote_scripts/micro-heartbeat/heartbeat.py 寫入 Redis，
 * key 帶 TTL —— key 不存在即視為離線，毋須額外比對時間。
 */
class MicroHostStatus
{
    /**
     * 完整 payload（含 VM/CT 清單）。
     *
     * @return array{status:string, host?:?string, last_seen?:?string, api_error?:?string, vms?:array<int,mixed>, cts?:array<int,mixed>, error?:string}
     */
    public function full(): array
    {
        try {
            $raw = Redis::get(config('micro-host.ttl_key'));
        } catch (\Throwable) {
            return ['status' => 'offline', 'error' => 'redis_unavailable'];
        }

        if (! $raw) {
            return ['status' => 'offline'];
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return ['status' => 'offline', 'error' => 'invalid_payload'];
        }

        return [
            'status'    => 'online',
            'host'      => $data['host'] ?? null,
            'last_seen' => $data['last_seen'] ?? null,
            'api_error' => $data['api_error'] ?? null,
            'vms'       => is_array($data['vms'] ?? null) ? $data['vms'] : [],
            'cts'       => is_array($data['cts'] ?? null) ? $data['cts'] : [],
        ];
    }
}

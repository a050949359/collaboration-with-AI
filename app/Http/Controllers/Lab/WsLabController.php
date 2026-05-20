<?php

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WsLabController extends Controller
{
    private string $binaryPath;
    private string $pidFile;
    private string $wsAddr;
    private string $mgmtAddr;

    public function __construct()
    {
        $this->binaryPath = storage_path('app/ws-lab');
        $this->pidFile    = storage_path('app/ws-lab.pid');
        $this->wsAddr     = '127.0.0.1:9001';
        $this->mgmtAddr   = '127.0.0.1:9002';
    }

    public function authToken(): JsonResponse
    {
        $token = Str::random(40);
        $r = new \Redis();
        $r->connect(
            config('database.redis.default.host', '127.0.0.1'),
            (int) config('database.redis.default.port', 6379),
        );
        $r->setex('ws-lab-auth:' . $token, 60, auth()->user()->name);
        $r->close();
        return response()->json(['token' => $token]);
    }

    public function rooms(): JsonResponse
    {
        if (!$this->isRunning()) {
            return response()->json([]);
        }
        try {
            $res = Http::timeout(2)->get("http://{$this->mgmtAddr}/rooms");
            return response()->json($res->json() ?? []);
        } catch (\Throwable) {
            return response()->json([]);
        }
    }

    public function status(): JsonResponse
    {
        return response()->json([
            'running' => $this->isRunning(),
            'pid'     => $this->readPid(),
        ]);
    }

    public function start(): JsonResponse
    {
        if (!file_exists($this->binaryPath)) {
            return response()->json(['message' => 'ws-lab binary not found. Run: go build -o storage/app/ws-lab ./cmd/ws-lab'], 503);
        }

        if ($this->isRunning()) {
            return response()->json(['message' => 'already running', 'pid' => $this->readPid()]);
        }

        $cmd = sprintf(
            '%s --ws-addr=%s --mgmt-addr=%s --pid-file=%s > /dev/null 2>&1 &',
            escapeshellarg($this->binaryPath),
            escapeshellarg($this->wsAddr),
            escapeshellarg($this->mgmtAddr),
            escapeshellarg($this->pidFile),
        );

        exec($cmd);

        // wait briefly for process to start and write PID
        usleep(300_000);

        return response()->json([
            'message' => 'started',
            'pid'     => $this->readPid(),
            'ws_addr' => $this->wsAddr,
        ]);
    }

    public function stop(): JsonResponse
    {
        if (!$this->isRunning()) {
            return response()->json(['message' => 'not running']);
        }

        try {
            Http::timeout(3)->post("http://{$this->mgmtAddr}/shutdown");
        } catch (\Throwable $e) {
            Log::warning('ws-lab shutdown via HTTP failed, falling back to kill', ['error' => $e->getMessage()]);
            $pid = $this->readPid();
            if ($pid) {
                exec("kill {$pid}");
            }
        }

        return response()->json(['message' => 'stopped']);
    }

    public function streamStart(): JsonResponse
    {
        if (!$this->isRunning()) {
            if (!file_exists($this->binaryPath)) {
                return response()->json(['message' => 'ws-lab binary not found'], 503);
            }
            $cmd = sprintf(
                '%s --ws-addr=%s --mgmt-addr=%s --pid-file=%s > /dev/null 2>&1 &',
                escapeshellarg($this->binaryPath),
                escapeshellarg($this->wsAddr),
                escapeshellarg($this->mgmtAddr),
                escapeshellarg($this->pidFile),
            );
            exec($cmd);
            usleep(300_000);
        }

        try {
            Http::timeout(3)->post("http://{$this->mgmtAddr}/stream/start");
        } catch (\Throwable $e) {
            return response()->json(['message' => 'server not ready'], 503);
        }

        return response()->json(['ok' => true]);
    }

    public function streamStop(): JsonResponse
    {
        if (!$this->isRunning()) {
            return response()->json(['ok' => true]);
        }

        try {
            Http::timeout(3)->post("http://{$this->mgmtAddr}/stream/stop");
        } catch (\Throwable $e) {
            Log::warning('ws-lab stream stop failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    private function isRunning(): bool
    {
        $pid = $this->readPid();
        if ($pid === null) {
            return false;
        }
        exec("kill -0 {$pid} 2>/dev/null", $out, $code);
        return $code === 0;
    }

    private function readPid(): ?int
    {
        if (!file_exists($this->pidFile)) {
            return null;
        }
        $pid = (int) trim((string) file_get_contents($this->pidFile));
        return $pid > 0 ? $pid : null;
    }
}

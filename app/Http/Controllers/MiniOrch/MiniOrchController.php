<?php

namespace App\Http\Controllers\MiniOrch;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class MiniOrchController extends Controller
{
    private function baseUrl(): string
    {
        return 'https://' . rtrim(config('services.mini_orch.host', ''), '/');
    }

    public function page(): InertiaResponse
    {
        return Inertia::render('MiniOrch');
    }

    public function dashboard(): Response
    {
        try {
            $res = Http::withoutVerifying()->timeout(8)->get($this->baseUrl() . ':5000/dashboard');

            return response($res->body(), $res->status())
                ->header('Content-Type', 'text/html; charset=utf-8');
        } catch (\Throwable $e) {
            return response($this->dashboardErrorHtml($e->getMessage()), 502)
                ->header('Content-Type', 'text/html; charset=utf-8');
        }
    }

    public function createRun(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vus'      => ['required', 'integer', 'min:1', 'max:1000'],
            'duration' => ['required', 'string', 'regex:/^\d+s$/'],
            'api_url'  => ['required', 'url', 'max:2048'],
        ]);

        try {
            $res = Http::withoutVerifying()->timeout(30)
                ->post($this->baseUrl() . ':5001/api/v1/loadtest/runs', $validated);

            return response()->json($res->json(), $res->status());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function getRun(string $runId): JsonResponse
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $runId)) {
            return response()->json(['message' => 'Invalid run ID'], 400);
        }

        try {
            $res = Http::withoutVerifying()->timeout(10)
                ->get($this->baseUrl() . ':5001/api/v1/loadtest/runs/' . $runId);

            return response()->json($res->json(), $res->status());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    private function dashboardErrorHtml(string $message): string
    {
        $msg = htmlspecialchars($message, ENT_QUOTES);
        $host = htmlspecialchars($this->baseUrl(), ENT_QUOTES);

        return <<<HTML
        <!doctype html>
        <html>
        <head><meta charset="utf-8"><title>mini-orch</title>
        <style>
            body { margin: 0; background: #0b100d; color: #a5d1b4; font-family: monospace;
                   display: flex; align-items: center; justify-content: center; height: 100vh; }
            .box { text-align: center; opacity: 0.7; }
            .code { color: #ffb3b2; font-size: 0.8rem; margin-top: 0.5rem; word-break: break-all; }
        </style>
        </head>
        <body>
            <div class="box">
                <div>⚠ node unreachable</div>
                <div class="code">{$host}:5000</div>
                <div class="code">{$msg}</div>
            </div>
        </body>
        </html>
        HTML;
    }
}

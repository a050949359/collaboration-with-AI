<?php

use App\Http\Middleware\AuthTokenFromCookie;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\HandleInertiaRequests;
use App\Rules\NoMaliciousPattern;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(TrustProxies::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // web group 也需要讀 auth_token cookie，讓 HandleInertiaRequests 可以拿到登入使用者
        $middleware->prependToGroup('web', AuthTokenFromCookie::class);
        $middleware->prependToGroup('api', AuthTokenFromCookie::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request): ?JsonResponse {
            if (!$request->expectsJson()) {
                return null;
            }

            $errors = $e->errors();

            $hasUnsafeInput = collect($errors)
                ->flatten()
                ->contains(static fn ($message) => is_string($message) && str_contains($message, NoMaliciousPattern::ERROR_TOKEN));

            if ($hasUnsafeInput) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'UNSAFE_INPUT',
                    'message' => '疑似不安全輸入',
                    'errors' => $errors,
                ], 422);
            }

            return response()->json([
                'status' => 'error',
                'code' => 'VALIDATION_ERROR',
                'message' => '輸入資料格式錯誤',
                'errors' => $errors,
            ], 422);
        });
    })->create();

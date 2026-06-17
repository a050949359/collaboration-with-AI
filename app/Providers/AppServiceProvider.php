<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Notifications\VerifyEmailNotification;
use App\Services\AI\Contracts\GeneratesArticleContent;
use App\Services\AI\Contracts\GeneratesArticleImage;
use App\Services\AI\Contracts\MultimodalEmbedding;
use App\Services\AI\Contracts\TextEmbedding;
use App\Services\AI\Gemini\GeminiEmbeddingService;
use App\Services\AI\Gemini\GeminiMultimodalEmbeddingService;
use App\Services\AI\LlmManager;
use App\Services\AI\VertexGeminiArticleService;
use App\Services\AI\VertexImageGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GeneratesArticleContent::class, VertexGeminiArticleService::class);
        $this->app->bind(GeneratesArticleImage::class, VertexImageGenerationService::class);
        $this->app->singleton(LlmManager::class);

        // RAG embedding（文字 + 多模態，皆走 gemini-embedding-2，預設由 config 決定）
        $this->app->bind(TextEmbedding::class, GeminiEmbeddingService::class);
        $this->app->bind(MultimodalEmbedding::class, GeminiMultimodalEmbeddingService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        $this->configureDefaults();

        VerifyEmail::toMailUsing(function ($notifiable, $url) {
            return new VerifyEmailNotification($url);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

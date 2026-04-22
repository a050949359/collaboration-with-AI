<?php

namespace App\Http\Controllers\Article;

use App\Http\Controllers\Controller;
use App\Http\Requests\Article\GenerateArticleContentRequest;
use App\Http\Requests\Article\GenerateArticleImageRequest;
use App\Http\Requests\Article\StoreArticleRequest;
use App\Jobs\GenerateArticleContentJob;
use App\Jobs\GenerateArticleImageJob;
use App\Models\Article;
use App\Enums\ArticleTopic;
use App\Enums\ArticleLanguage;
use App\Enums\ArticleStyle;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

class ArticleGenerationController extends Controller
{
    use ApiResponse;

    public function store(StoreArticleRequest $request): JsonResponse
    {
        $article = Article::create([
            'user_id' => $request->user()->id,
            'title' => $request->input('title'),
            'prompt' => $request->input('prompt'),
            'content_status' => 'pending',
            'image_status' => 'pending',
        ]);

        return $this->success($this->toPayload($article), 'Article draft created.', 201);
    }

    public function show(Article $article): JsonResponse
    {
        if (!$this->canAccess($article)) {
            return $this->error('權限不足', 403);
        }

        return $this->success($this->toPayload($article));
    }

    public function generateContent(
        GenerateArticleContentRequest $request,
        Article $article,
    ): JsonResponse {
        if (!$this->canAccess($article)) {
            return $this->error('權限不足', 403);
        }

        if ($limitError = $this->ensureRateLimit('content')) {
            return $limitError;
        }

        if (!$this->canGenerateContent($article)) {
            return $this->error('僅能對新草稿使用文章生成。', 422);
        }

        $topic    = ArticleTopic::from((string) $request->input('topic'));
        $language = ArticleLanguage::from((string) $request->input('language'));
        $style    = ArticleStyle::from((string) $request->input('style'));
        $prompt   = trim((string) $request->input('prompt', ''));

        $article->update([
            'category'       => $topic->value,
            'prompt'         => $prompt !== '' ? $prompt : $article->prompt,
            'content_status' => 'processing',
            'content_error'  => null,
        ]);

        GenerateArticleContentJob::dispatch(
            $article->id,
            $topic,
            $language,
            $style,
            $prompt !== '' ? $prompt : null,
        );

        return $this->success($this->toPayload($article->fresh()), 'Article content generation queued.', 202);
    }

    public function generateImage(
        GenerateArticleImageRequest $request,
        Article $article,
    ): JsonResponse {
        if (!$this->canAccess($article)) {
            return $this->error('權限不足', 403);
        }

        if ($limitError = $this->ensureRateLimit('image')) {
            return $limitError;
        }

        if (!$this->canGenerateImage($article)) {
            return $this->error('僅能對新草稿使用圖片生成。', 422);
        }

        $article->update([
            'image_status' => 'processing',
            'image_error' => null,
        ]);

        GenerateArticleImageJob::dispatch(
            $article->id,
            (string) $request->input('aspect_ratio', '16:9'),
        );

        return $this->success($this->toPayload($article->fresh()), 'Article image generation queued.', 202);
    }

    private function canAccess(Article $article): bool
    {
        $user = request()->user();

        if (!$user) {
            return false;
        }

        return $article->user_id === $user->id || $user->isAdmin();
    }

    private function ensureRateLimit(string $type): ?JsonResponse
    {
        $user = request()->user();
        $seconds = (int) config('services.vertex_ai.rate_limit_seconds', 3600);
        $key = sprintf('article:%s-generation:%s', $type, $user->id);

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return $this->error('Too many requests. Please retry later.', 429, [
                'retry_after' => RateLimiter::availableIn($key),
            ]);
        }

        RateLimiter::hit($key, $seconds);

        return null;
    }

    private function canGenerateContent(Article $article): bool
    {
        return blank($article->content)
            && blank($article->content_generated_at)
            && $article->content_status !== 'processing';
    }

    private function canGenerateImage(Article $article): bool
    {
        return blank($article->image_url)
            && blank($article->image_generated_at)
            && $article->image_status !== 'processing';
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(Article $article): array
    {
        return [
            'id' => $article->id,
            'user_id' => $article->user_id,
            'title' => $article->title,
            'category' => $article->category,
            'summary' => $article->summary,
            'tags' => is_array($article->tags) ? $article->tags : [],
            'prompt' => $article->prompt,
            'content' => $article->content,
            'image_url' => $article->image_url,
            'content_status' => $article->content_status,
            'image_status' => $article->image_status,
            'content_error' => $article->content_error,
            'image_error' => $article->image_error,
            'content_generated_at' => $article->content_generated_at,
            'image_generated_at' => $article->image_generated_at,
            'created_at' => $article->created_at,
            'updated_at' => $article->updated_at,
        ];
    }
}

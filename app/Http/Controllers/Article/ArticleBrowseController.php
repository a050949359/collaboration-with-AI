<?php

namespace App\Http\Controllers\Article;

use App\Http\Controllers\Controller;
use App\Models\Article\Article;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArticleBrowseController extends Controller
{
    use ApiResponse;

    public function publicIndex(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $articles = Article::query()
            ->where('content_status', 'completed')
            ->latest()
            ->paginate($request->integer('per_page', 12));

        $paginator = $articles->through(fn (Article $article) => $this->previewPayload($article));

        return $this->paginated($paginator);
    }

    public function authIndex(Request $request): JsonResponse
    {
        $request->validate([
            'scope' => ['nullable', 'in:all,mine'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $scope = $request->input('scope', 'all');
        $userId = $request->user()->id;
        $query = Article::query()->latest();

        if ($scope === 'mine') {
            $query->where('user_id', $userId);
        } else {
            $query->where('content_status', 'completed');
        }

        $articles = $query->paginate($request->integer('per_page', 10));

        $paginator = $articles->through(fn (Article $article) => $this->previewPayload($article));

        return $this->paginated($paginator);
    }

    public function publicShow(Article $article): JsonResponse
    {
        if ($article->content_status !== 'completed') {
            abort(404);
        }

        return $this->success($this->detailPayload($article));
    }

    /**
     * @return array<string, mixed>
     */
    private function previewPayload(Article $article): array
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
            'category' => $article->category ?: 'UNCATEGORIZED',
            'summary' => $article->summary ?: $this->excerpt($article->content),
            'tags' => is_array($article->tags) ? $article->tags : [],
            'image_url' => $article->image_url,
            'content_status' => $article->content_status,
            'image_status' => $article->image_status,
            'user_id' => $article->user_id,
            'created_at' => $article->created_at,
            'updated_at' => $article->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(Article $article): array
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
            'category' => $article->category ?: 'UNCATEGORIZED',
            'summary' => $article->summary ?: $this->excerpt($article->content),
            'tags' => is_array($article->tags) ? $article->tags : [],
            'prompt' => $article->prompt,
            'content' => $article->content,
            'image_url' => $article->image_url,
            'content_status' => $article->content_status,
            'image_status' => $article->image_status,
            'user_id' => $article->user_id,
            'created_at' => $article->created_at,
            'updated_at' => $article->updated_at,
        ];
    }

    private function excerpt(?string $content, int $limit = 180): string
    {
        $text = trim(strip_tags((string) $content));

        if ($text === '') {
            return '';
        }

        return mb_strlen($text) > $limit
            ? mb_substr($text, 0, $limit).'...'
            : $text;
    }
}

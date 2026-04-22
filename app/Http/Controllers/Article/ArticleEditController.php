<?php

namespace App\Http\Controllers\Article;

use App\Http\Controllers\Controller;
use App\Http\Requests\Article\UpdateArticleRequest;
use App\Models\Article;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArticleEditController extends Controller
{
    use ApiResponse;

    public function update(UpdateArticleRequest $request, Article $article): JsonResponse
    {
        if ($article->user_id !== $request->user()->id) {
            return $this->error('權限不足', 403);
        }

        $article->update($request->only(['title', 'content', 'summary', 'tags']));

        return $this->success($this->toPayload($article->fresh()), 'Article updated.');
    }

    public function destroy(Request $request, Article $article): JsonResponse
    {
        if ($article->user_id !== $request->user()->id) {
            return $this->error('權限不足', 403);
        }

        $article->delete();

        return $this->success(null, 'Article deleted.');
    }

    /** @return array<string, mixed> */
    private function toPayload(Article $article): array
    {
        return [
            'id'             => $article->id,
            'user_id'        => $article->user_id,
            'title'          => $article->title,
            'category'       => $article->category,
            'summary'        => $article->summary,
            'tags'           => is_array($article->tags) ? $article->tags : [],
            'prompt'         => $article->prompt,
            'content'        => $article->content,
            'image_url'      => $article->image_url,
            'content_status' => $article->content_status,
            'image_status'   => $article->image_status,
            'content_error'  => $article->content_error,
            'image_error'    => $article->image_error,
            'created_at'     => $article->created_at,
            'updated_at'     => $article->updated_at,
        ];
    }
}

<?php

namespace App\Http\Controllers\Article;

use App\Http\Controllers\Controller;
use App\Http\Requests\Article\ArticleCommentBodyRequest;
use App\Http\Requests\Article\ArticleCommentRequest;
use App\Models\Article\Article;
use App\Models\Article\ArticleComment;

class ArticleCommentController extends Controller
{
    public function index(Article $article)
    {
        $comments = ArticleComment::query()
            ->where('article_id', $article->id)
            ->with('user')
            ->with('children.user')
            ->whereNull('parent_id')
            ->get();

        return response()->json($comments);
    }

    public function store(ArticleCommentRequest $request, Article $article)
    {
        if (auth()->check()) {
            ArticleComment::create([
                'article_id' => $article->id,
                'user_id' => auth()->id(),
                'guest_id' => null,
                'guest_name' => null,
                'body' => $request->body,
                'parent_id' => $request->parent_id,
            ]);
        } else {
            if (!request()->cookie('guest_id')) {
                // 為訪客生成一個 UUID，並設置在 cookie 中，過期時間為 1 年
                $guestId = (string) \Illuminate\Support\Str::uuid();
                cookie()->queue(cookie('guest_id', $guestId, 60 * 24 * 365)); // 1 年
            } else {
               $guestId = request()->cookie('guest_id');
            }

            ArticleComment::create([
                'article_id' => $article->id,
                'user_id' => null,
                'guest_id' => $guestId,
                'guest_name' => $request->guest_name,
                'body' => $request->body,
                'parent_id' => $request->parent_id,
            ]);
        }

        return response()->json(['message' => 'Comment created.'], 201);
    }

    public function update(ArticleCommentBodyRequest $request, ArticleComment $articleComment)
    {
        if ($articleComment->trashed()) {
            return response()->json(['message' => 'Comment has been deleted.'], 410);
        }

        // 評論修正權限判斷
        if(!is_null($articleComment->user_id)) {
            if ($articleComment->user_id !== auth()->id()) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }
        } else {
            if ($articleComment->guest_id === null) {
                return response()->json(['message' => 'comment does not belong to anyone'], 403);
            }

            $guestId = request()->cookie('guest_id');
            if ($articleComment->guest_id !== $guestId) {
                return response()->json(['message' => 'comment does not belong to this guest'], 403);
            }
        }

        $articleComment->body = $request->input('body');
        if ($request->filled('guest_name')) {
            $articleComment->guest_name = $request->guest_name;
        }
        $success = $articleComment->save();

        return response()->json([
            'message' => $success ? 'Comment updated.' : 'update failed.',
        ], $success ? 200 : 400);
    }

    public function destroy(ArticleComment $articleComment)
    {
        if ($articleComment->trashed()) {
            return response()->json(['message' => 'Comment has been deleted.'], 410);
        }

        if (auth()->check()) {
            if (!request()->user()->isAdmin()) {
                if ($articleComment->user_id !== auth()->id()) {
                    return response()->json(['message' => 'Unauthorized.'], 403);
                }
            }
        } else {
            if ($articleComment->guest_id === null) {
                return response()->json(['message' => 'comment does not belong to anyone'], 403);
            }

            $guestId = request()->cookie('guest_id');
            if ($articleComment->guest_id !== $guestId) {
                return response()->json(['message' => 'comment does not belong to this guest'], 403);
            }
        }

        $articleComment->delete();

        return response()->noContent();
    }
}
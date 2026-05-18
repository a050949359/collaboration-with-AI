<?php

namespace App\Http\Controllers\Article;

use App\Http\Controllers\Controller;
use App\Http\Requests\Article\ArticleCommentBodyRequest;
use App\Http\Requests\Article\ArticleCommentRequest;
use App\Models\Article\Article;
use App\Models\Article\ArticleComment;
use Illuminate\Http\Request;

class ArticleCommentController extends Controller
{
    public function index(Request $request, Article $article)
    {
        $comments = ArticleComment::query()
            ->where('article_id', $article->id)
            ->with('user')
            ->with('children.user')
            ->whereNull('parent_id')
            ->get();

        $cookieGuestId = $request->cookie('guest_id');
        $userId = auth()->id();

        $comments->each(function (ArticleComment $comment) use ($cookieGuestId, $userId) {
            $comment->can_edit = $userId
                ? $userId === $comment->user_id
                : ($cookieGuestId !== null && $cookieGuestId === $comment->guest_id);

            $comment->children->each(function (ArticleComment $child) use ($cookieGuestId, $userId) {
                $child->can_edit = $userId
                    ? $userId === $child->user_id
                    : ($cookieGuestId !== null && $cookieGuestId === $child->guest_id);
            });
        });

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
            $response = response()->json(['message' => 'Comment created.'], 201);
        } else {
            $existingGuestId = $request->cookie('guest_id');
            $guestId = $existingGuestId ?? (string) \Illuminate\Support\Str::uuid();
            $isNewGuest = $existingGuestId === null;

            ArticleComment::create([
                'article_id' => $article->id,
                'user_id' => null,
                'guest_id' => $guestId,
                'guest_name' => $request->guest_name,
                'body' => $request->body,
                'parent_id' => $request->parent_id,
            ]);

            $response = response()->json(['message' => 'Comment created.'], 201);

            if ($isNewGuest) {
                $response->withCookie(cookie('guest_id', $guestId, 60 * 24 * 365));
            }
        }

        return $response;
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
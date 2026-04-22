<?php

namespace App\Http\Controllers\Article;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArticlePageController extends Controller
{
    public function show(Article $article): Response
    {
        return Inertia::render('Articles/Show', [
            'articleId' => $article->id,
        ]);
    }

    public function generateNew(): Response
    {
        return Inertia::render('Articles/Generate');
    }

    public function edit(Request $request, Article $article): Response
    {
        $this->ensureOwner($request, $article);

        return Inertia::render('Articles/Edit', [
            'articleId' => $article->id,
        ]);
    }

    private function ensureOwner(Request $request, Article $article): void
    {
        if ($article->user_id !== $request->user()->id) {
            abort(403);
        }
    }
}

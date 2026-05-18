<?php

namespace App\Http\Controllers;

use App\Models\Article\Article;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        $latestArticles = Article::query()
            ->where('content_status', 'completed')
            ->latest()
            ->limit(3)
            ->get()
            ->map(function (Article $article): array {
                $content = trim(strip_tags((string) $article->content));

                return [
                    'date' => $article->created_at?->format('Y.m.d') ?? '',
                    'category' => $article->category ?: 'UNCATEGORIZED',
                    'title' => $article->title ?: 'Untitled',
                    'description' => $article->summary ?: Str::limit($content, 180, '...'),
                    'tags' => is_array($article->tags) ? $article->tags : [],
                ];
            })
            ->values();

        return Inertia::render('Home', [
            'latestArticles' => $latestArticles,
        ]);
    }
}

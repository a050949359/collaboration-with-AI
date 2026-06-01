<?php

namespace App\Http\Controllers\Line;

use App\Enums\ArticleLanguage;
use App\Enums\ArticleStyle;
use App\Enums\ArticleTopic;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateArticleContentJob;
use App\Models\Article\Article;
use App\Models\SocialAccount;
use App\Support\LineBotHmac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LineArticleController extends Controller
{
    public function quickGenerate(Request $request): JsonResponse
    {
        if ($authError = $this->authorizeInternalRequest($request)) {
            return $authError;
        }

        $payload = $request->validate([
            'line_user_id' => ['required', 'string', 'max:64'],
            'topic'    => ['required', Rule::enum(ArticleTopic::class)],
            'language' => ['required', Rule::enum(ArticleLanguage::class)],
            'style'    => ['required', Rule::enum(ArticleStyle::class)],
            'prompt' => ['nullable', 'string', 'max:300'],
        ]);

        $social = SocialAccount::query()
            ->where('provider', 'line')
            ->where('provider_user_id', $payload['line_user_id'])
            ->first();

        if (!$social) {
            return response()->json([
                'message' => '找不到對應的 LINE 綁定使用者',
            ], 404);
        }

        $topic = ArticleTopic::from((string) $payload['topic']);
        $language = ArticleLanguage::from((string) $payload['language']);
        $style = ArticleStyle::from((string) $payload['style']);
        $prompt = trim((string) ($payload['prompt'] ?? ''));

        $article = Article::create([
            'user_id' => $social->user_id,
            'created_via' => 'line',
            'prompt' => $prompt !== '' ? $prompt : null,
            'content_status' => 'processing',
            'image_status' => 'pending',
            'category' => $topic->value,
        ]);

        GenerateArticleContentJob::dispatch(
            $article->id,
            $topic,
            $language,
            $style,
            $prompt !== '' ? $prompt : null,
        );

        return response()->json([
            'message' => '文章生成已加入佇列',
            'article' => [
                'id' => $article->id,
                'user_id' => $article->user_id,
                'created_via' => $article->created_via,
                'content_status' => $article->content_status,
                'image_status' => $article->image_status,
            ],
        ], 202);
    }

    private function authorizeInternalRequest(Request $request): ?JsonResponse
    {
        $expected = (string) config('services.line_bot.internal_api_key', '');
        $provided = (string) $request->header('X-Line-Bot-Key', '');

        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Unauthorized internal request',
            ], 401);
        }

        $hmac = app(LineBotHmac::class);

        if (!$hmac->verifyInbound($request)) {
            return response()->json([
                'message' => 'Unauthorized internal request',
            ], 401);
        }

        return null;
    }
}

<?php

namespace App\Jobs;

use App\Models\Article;
use App\Support\LineBotHmac;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DispatchLineArticleReadyWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public int $articleId)
    {
    }

    public function handle(): void
    {
        $webhookUrl = (string) config('services.line_bot.article_ready_webhook_url', '');

        if ($webhookUrl === '') {
            return;
        }

        $article = Article::query()
            ->with(['user.socialAccounts'])
            ->find($this->articleId);

        if (!$article || $article->content_status !== 'completed' || $article->created_via !== 'line') {
            return;
        }

        $user = $article->user;

        if (!$user) {
            return;
        }

        $lineAccount = $user->socialAccounts
            ->firstWhere('provider', 'line');

        if (!$lineAccount) {
            return;
        }

        $payload = [
            'event' => [
                'type' => 'article_ready',
                'event_id' => sprintf('article_ready:%d:user:%d', $article->id, $user->id),
                'occurred_at' => now()->toIso8601String(),
            ],
            'user' => [
                'id' => $user->id,
                'line_user_id' => $lineAccount->provider_user_id,
                'name' => $user->name,
            ],
            'article' => [
                'id' => $article->id,
                'title' => $article->title,
                'summary' => $article->summary,
                'url' => rtrim((string) config('app.url'), '/').'/app/articles/'.$article->id,
                'category' => $article->category,
                'created_via' => $article->created_via,
                'content_generated_at' => optional($article->content_generated_at)?->toIso8601String(),
            ],
        ];

        $request = Http::timeout(8)
            ->acceptJson();

        $rawPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if (!is_string($rawPayload)) {
            return;
        }

        $request = $request->withBody($rawPayload, 'application/json');

        $apiKey = (string) config('services.line_bot.outbound_webhook_key', '');
        $hmac = app(LineBotHmac::class);
        $hmacHeaders = $hmac->buildOutboundHeaders($rawPayload);
        $headers = $hmacHeaders;

        if ($apiKey !== '') {
            $headers['X-Line-Webhook-Key'] = $apiKey;
        }

        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }

        $request->post($webhookUrl)->throw();
    }
}

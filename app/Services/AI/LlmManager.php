<?php

namespace App\Services\AI;

use App\Services\AI\Contracts\ChatCompletion;
use App\Services\AI\Gemini\GeminiChatService;
use App\Services\AI\Nvidia\NvidiaChatService;
use App\Services\AI\Ollama\OllamaChatService;
use Illuminate\Support\Facades\Cache;

/**
 * 依「用途」解析出對應的 LLM driver。
 *
 * 每個用途（story / story_state / character / chat）的 provider+model：
 *   runtime 由 admin_settings.llm.<use> 覆蓋 → 否則用 config('services.llm.uses.<use>') 預設。
 */
class LlmManager
{
    /** 與 Admin\SettingsController 共用的 cache key。 */
    private const SETTINGS_KEY = 'admin_settings';

    /**
     * 取得某用途的 driver。
     */
    public function for(string $use): ChatCompletion
    {
        [$provider, $model] = $this->resolveUse($use);

        return $this->driver($provider, $model);
    }

    /**
     * 直接依 provider 名稱建 driver（測試端點 / 臨時切換用）。
     */
    public function driver(string $provider, ?string $model = null): ChatCompletion
    {
        return match ($provider) {
            'gemini' => new GeminiChatService($model),
            'nvidia' => new NvidiaChatService($model),
            'ollama' => new OllamaChatService($model),
            default  => throw new AIServiceException("Unknown LLM provider: {$provider}"),
        };
    }

    /**
     * 解析用途 → [provider, model]，runtime 設定優先於 config 預設。
     *
     * @return array{0: string, 1: string}
     */
    public function resolveUse(string $use): array
    {
        $default = config("services.llm.uses.{$use}", [
            'provider' => 'gemini',
            'model'    => (string) config('services.gemini.model'),
        ]);

        // Redis 掛掉時退回 config 預設，不讓設定讀取打斷 LLM 呼叫。
        $settings = rescue(fn() => Cache::get(self::SETTINGS_KEY), null);
        $override = is_array($settings) ? ($settings['llm'][$use] ?? null) : null;

        $provider = (string) ($override['provider'] ?? $default['provider']);
        $model    = (string) ($override['model'] ?? $default['model']);

        return [$provider, $model];
    }
}

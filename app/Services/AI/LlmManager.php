<?php

namespace App\Services\AI;

use App\Enums\LlmUse;
use App\Services\AI\Contracts\ChatCompletion;
use App\Services\AI\Gemini\GeminiChatService;
use App\Services\AI\Nvidia\NvidiaChatService;
use App\Services\AI\Ollama\OllamaChatService;
use App\Support\AppSettings;

/**
 * 依「用途」解析出對應的 LLM driver。
 *
 * 每個用途（story / story_state / character / chat）的 provider+model：
 *   runtime 由 admin_settings.llm.<use> 覆蓋 → 否則用 config('services.llm.uses.<use>') 預設。
 */
class LlmManager
{
    /**
     * 取得某用途的 driver。
     */
    public function for(string|LlmUse $use): ChatCompletion
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
            default => throw new AIServiceException("Unknown LLM provider: {$provider}"),
        };
    }

    /**
     * 解析用途 → [provider, model]，runtime 設定優先於 config 預設。
     *
     * @return array{0: string, 1: string}
     */
    public function resolveUse(string|LlmUse $use): array
    {
        $key = $use instanceof LlmUse ? $use->value : $use;

        // config 沒列此用途 → 退回 gemini 預設(用途以 LlmUse 為準,config 只是 env 覆蓋)
        $default = config("services.llm.uses.{$key}", [
            'provider' => 'gemini',
            'model' => (string) config('services.gemini.model'),
        ]);

        // AppSettings 內部已 rescue：Redis 掛掉時退回 config 預設，不讓設定讀取打斷 LLM 呼叫。
        $llm = AppSettings::get('llm', []);
        $override = is_array($llm) ? ($llm[$key] ?? null) : null;

        $provider = (string) ($override['provider'] ?? $default['provider']);
        $model = (string) ($override['model'] ?? $default['model']);

        return [$provider, $model];
    }
}

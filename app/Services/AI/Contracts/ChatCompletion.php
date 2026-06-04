<?php

namespace App\Services\AI\Contracts;

interface ChatCompletion
{
    /**
     * Send a generation request with a system prompt and optional message history.
     *
     * Provider-neutral: each adapter translates $messages roles and $options
     * into its own request shape (Gemini generationConfig, OpenAI response_format, …).
     *
     * @param  array<int, array{role: string, text: string}>  $messages  role: user|assistant
     * @param  array{json_schema?: array<string, mixed>, temperature?: float, max_tokens?: int}  $options
     */
    public function generate(string $systemPrompt, array $messages = [], array $options = []): string;
}

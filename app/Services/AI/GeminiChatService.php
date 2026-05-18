<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Storage;

class GeminiChatService
{
    public function __construct(private GeminiService $gemini) {}

    /**
     * Send a multi-turn chat message grounded on imported resume context.
     *
     * @param  array<int, array{role: string, text: string}>  $history
     */
    public function chat(string $message, array $history = []): string
    {
        $context = $this->loadContext();

        if ($context === '') {
            return '目前尚未匯入任何履歷背景資料，請先由管理員在 About 頁面匯入 Context。';
        }

        $messages   = $history;
        $messages[] = ['role' => 'user', 'text' => $message];

        return $this->gemini->generate($this->buildSystemPrompt($context), $messages);
    }

    public function saveContext(string $context): void
    {
        Storage::put('private/resume_context.md', $context);
    }

    public function loadContext(): string
    {
        if (!Storage::exists('private/resume_context.md')) {
            return '';
        }

        return (string) Storage::get('private/resume_context.md');
    }

    private function buildSystemPrompt(string $context): string
    {
        return implode("\n\n", [
            '你是一位後端工程師的個人助理，負責代表他回答訪客的問題。',
            '以下是關於這位工程師的背景資料，請只能依據這些資料回答問題。',
            '如果資料中沒有明確資訊，請直接回答：「這部分我的資料裡沒有記載，我不想亂回答。」',
            '禁止推測、禁止腦補、禁止自行添加未提供的經歷、技能、公司、學歷、年份與數字。',
            '若問題要求比較、評價或延伸建議，也只能基於已提供資料進行，不能虛構前提。',
            '請用親切、專業的語氣回答，以第一人稱「我」代表這位工程師說話。',
            '--- 背景資料 ---',
            $context,
            '--- 資料結束 ---',
        ]);
    }
}

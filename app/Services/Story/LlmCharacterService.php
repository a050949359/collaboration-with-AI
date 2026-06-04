<?php

namespace App\Services\Story;

use App\Services\AI\LlmManager;

class LlmCharacterService
{
    private const CHARACTER_SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'name'       => ['type' => 'string'],
            'persona'    => ['type' => 'string'],
            'secret'     => ['type' => 'string', 'nullable' => true],
            'background' => ['type' => 'string'],
            'appearance' => [
                'type'       => 'object',
                'properties' => [
                    'age'      => ['type' => 'string'],
                    'hair'     => ['type' => 'string'],
                    'eyes'     => ['type' => 'string'],
                    'build'    => ['type' => 'string'],
                    'features' => ['type' => 'string', 'nullable' => true],
                ],
                'required' => ['age', 'hair', 'eyes', 'build'],
            ],
            'outfit' => ['type' => 'string'],
        ],
        'required' => ['name', 'persona', 'background', 'appearance', 'outfit'],
    ];

    private const IMAGE_PROMPT_SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'image_prompt' => ['type' => 'string'],
        ],
        'required' => ['image_prompt'],
    ];

    public function __construct(private LlmManager $llm) {}

    /**
     * Generate a full character design from a description (or auto-generate if empty).
     */
    public function generate(string $description = '', string $genre = 'fantasy'): array
    {
        $systemPrompt = implode("\n\n", [
            '你是一位角色設計師，擅長創造有深度、有特色的故事人物。',
            '請根據以下描述設計一個完整的角色。若無描述，請自由創作一個符合類型基調的有趣角色。',
            '類型基調：' . $this->genreHint($genre),
            implode("\n", [
                '欄位說明：',
                '- name：角色全名',
                '- persona：個性、動機、行為模式（100 字以內）',
                '- secret：不為人知的秘密或隱藏動機（可空）',
                '- background：背景故事（100 字以內）',
                '- appearance：外貌（age 年齡描述、hair 髮型髮色、eyes 眼睛、build 體型、features 特徵）',
                '- outfit：常見服裝打扮',
            ]),
        ]);

        $messages = [
            ['role' => 'user', 'text' => $description !== '' ? "描述：{$description}" : '請自由創作一個角色。'],
        ];

        $raw = $this->llm->for('character')->generate($systemPrompt, $messages, ['json_schema' => self::CHARACTER_SCHEMA]);

        return $this->decode($raw);
    }

    /**
     * Refine an existing character after user edits.
     *
     * @param  array<string, mixed>  $character
     */
    public function refine(array $character, string $notes = ''): array
    {
        $systemPrompt = implode("\n\n", [
            '你是一位角色設計師，使用者已對角色草稿進行修改，請根據修改後的版本做最終優化。',
            '目標：補足前後矛盾、強化個性一致性、讓背景故事更有說服力。',
            '保留使用者的所有修改意圖，只做潤飾與補強。',
        ]);

        $notesText = $notes !== '' ? "\n\n補充說明：{$notes}" : '';

        $messages = [
            [
                'role' => 'user',
                'text' => "以下是使用者修改後的角色設定，請優化：\n\n"
                    . json_encode($character, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                    . $notesText,
            ],
        ];

        $raw = $this->llm->for('character')->generate($systemPrompt, $messages, ['json_schema' => self::CHARACTER_SCHEMA]);

        return $this->decode($raw);
    }

    /**
     * Generate an image generation prompt from the finalized character data.
     *
     * @param  array<string, mixed>  $character
     */
    public function generateImagePrompt(array $character): string
    {
        $systemPrompt = implode("\n\n", [
            '你是一位專業的圖像生成 prompt 撰寫師。',
            '根據角色設定，生成一段適合用於 AI 圖像生成（Stable Diffusion / Midjourney / DALL-E）的英文 prompt。',
            'prompt 需包含：外貌特徵、服裝、姿態、背景氛圍、畫風（建議 digital art 或 illustration）。',
            '長度控制在 100 字以內，以逗號分隔關鍵詞為主。',
        ]);

        $messages = [
            [
                'role' => 'user',
                'text' => "角色設定：\n" . json_encode($character, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ],
        ];

        $raw = $this->llm->for('character')->generate($systemPrompt, $messages, ['json_schema' => self::IMAGE_PROMPT_SCHEMA]);

        $decoded = $this->decode($raw);

        return (string) ($decoded['image_prompt'] ?? $raw);
    }

    /** @return array<string, mixed> */
    private function decode(string $raw): array
    {
        $decoded = json_decode(trim($raw), true);

        return is_array($decoded) ? $decoded : ['raw' => $raw];
    }

    private function genreHint(string $genre): string
    {
        return match ($genre) {
            'mystery' => '懸疑推理，強調資訊不對稱、謊言與真相',
            'scifi'   => '科幻，強調科技設定的自洽性與未來感',
            'modern'  => '現代寫實，貼近日常生活，衝突來自人際與社會',
            default   => '奇幻，允許魔法、異世界、非人種族等超自然元素',
        };
    }
}

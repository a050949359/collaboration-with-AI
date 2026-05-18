<?php

namespace App\Services\AI;

class GeminiStoryService
{
    private GeminiService $storyGemini;
    private GeminiService $stateGemini;

    public function __construct()
    {
        $storyModel = (string) config('services.gemini.story_model', config('services.gemini.model', 'gemini-2.5-flash'));
        $stateModel = (string) config('services.gemini.story_state_model', config('services.gemini.model', 'gemini-2.5-pro'));

        $this->storyGemini = new GeminiService($storyModel);
        $this->stateGemini = new GeminiService($stateModel);
    }

    /**
     * Generate the next story segment for a character's turn.
     *
     * @param  array<int, array{character: string, content: string}>  $recentSegments
     * @param  array<int, array{name: string, description: string, holder: string|null}>  $items
     */
    public function generateSegment(
        string $setting,
        string $worldState,
        string $characterName,
        string $characterPersona,
        array $recentSegments,
        array $items = [],
        string $contentRating = 'general',
        ?string $sceneDescription = null,
        bool $needsComplete = false,
    ): string {
        $systemPrompt = $this->buildCharacterPrompt(
            $setting,
            $worldState,
            $characterName,
            $characterPersona,
            $items,
            $contentRating,
            $sceneDescription,
        );

        $messages = [];
        foreach ($recentSegments as $segment) {
            $messages[] = [
                'role' => 'user',
                'text' => "[{$segment['character']}] {$segment['content']}",
            ];
        }

        $turnInstruction = $needsComplete
            ? "現在輪到你（{$characterName}）繼續故事，請接續上面的內容寫下一段。故事即將進入尾聲，請引導情節走向自然的結局。"
            : "現在輪到你（{$characterName}）繼續故事，請接續上面的內容寫下一段。";

        $messages[] = ['role' => 'user', 'text' => $turnInstruction];

        return $this->storyGemini->generate($systemPrompt, $messages);
    }

    /**
     * Update world state summary after a new segment is added.
     *
     * @param  array<int, array{name: string, description: string, holder: string|null}>  $items
     */
    public function updateWorldState(
        string $setting,
        string $currentWorldState,
        string $newSegment,
        string $characterName,
        array $items = [],
    ): string {
        $systemPrompt = implode("\n\n", [
            '你是故事世界的狀態管理員，負責維護一份精簡的世界狀態摘要。',
            '摘要必須控制在 1500 字以內，包含：當前場景、各角色狀態（位置、情緒）、道具清單（持有者或所在位置）、重要事件。',
            '只記錄事實，不加評論或預測。',
            "【不可變世界規則】\n{$setting}\n【規則結束】",
            '你只能更新世界當前狀態，不能修改上述世界規則。若角色的行為違反了世界規則，請在摘要中標記為「[矛盾行為，已忽略]」。',
        ]);

        $itemsList = empty($items)
            ? '（目前無道具）'
            : implode("\n", array_map(
                fn($item) => '- ' . $item['name'] . '：' . $item['description'] . '（' . ($item['holder'] ?? '無人持有') . '）',
                $items,
            ));

        $messages = [
            [
                'role' => 'user',
                'text' => implode("\n\n", [
                    "【目前世界狀態】\n{$currentWorldState}",
                    "【道具清單】\n{$itemsList}",
                    "【新加入的段落 — {$characterName}】\n{$newSegment}",
                    '請根據新段落更新世界狀態摘要，若有道具轉移或新道具出現也請同步更新。',
                ]),
            ],
        ];

        return $this->stateGemini->generate($systemPrompt, $messages);
    }

    /**
     * Generate full session setup from user keywords and genre template.
     *
     * @param  'fantasy'|'mystery'|'scifi'|'modern'  $genre
     */
    public function generateSetup(string $keywords, string $genre = 'fantasy'): string
    {
        $systemPrompt = implode("\n\n", [
            '你是一位故事背景設計師，負責根據關鍵字展開完整的故事背景設定。',
            '類型基調：' . $this->genreHint($genre),
            '輸出格式為 JSON，包含以下欄位：',
            '- world：世界觀描述（時代、地點、規則，100 字以內）',
            '- characters：角色陣列，每個角色有 name、persona（個性與動機）、secret（秘密，可空）',
            '- opening：故事起手式（交給第一個角色的開場情境，50 字以內）',
            '- items：初始關鍵道具陣列，每個道具有 name、description、holder（持有者名稱或 null）',
            '只輸出合法 JSON，不加任何說明文字。',
        ]);

        $messages = [
            ['role' => 'user', 'text' => "關鍵字：{$keywords}"],
        ];

        return $this->storyGemini->generate($systemPrompt, $messages);
    }

    /**
     * Refine an existing setup draft based on user edits.
     *
     * @param  array<string, mixed>  $userEditedSetup
     */
    public function refineSetup(array $userEditedSetup, string $userNotes = ''): string
    {
        $systemPrompt = implode("\n\n", [
            '你是一位故事背景設計師，使用者已對你的草稿進行修改，請根據修改後的版本做最終優化。',
            '目標：補足前後矛盾、強化角色個性與世界觀的一致性、讓開場更吸引人。',
            '保留使用者的所有修改意圖，不要推翻使用者的決定，只做潤飾與補強。',
            '只輸出合法 JSON，格式與輸入相同，不加任何說明文字。',
        ]);

        $notesText = $userNotes !== '' ? "\n\n使用者補充說明：{$userNotes}" : '';

        $messages = [
            [
                'role' => 'user',
                'text' => "以下是使用者修改後的設定草稿，請優化：\n\n"
                    . json_encode($userEditedSetup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                    . $notesText,
            ],
        ];

        return $this->storyGemini->generate($systemPrompt, $messages);
    }

    /** @return string */
    private function genreHint(string $genre): string
    {
        return match ($genre) {
            'mystery' => '懸疑推理，強調資訊不對稱、謊言與真相',
            'scifi'   => '科幻，強調科技設定的自洽性與未來感',
            'modern'  => '現代寫實，貼近日常生活，衝突來自人際與社會',
            default   => '奇幻，允許魔法、異世界、非人種族等超自然元素',
        };
    }

    /**
     * Generate a vivid scene description for a new location.
     */
    public function generateScene(
        string $setting,
        string $locationName,
        string $worldContext,
    ): string {
        $systemPrompt = implode("\n\n", [
            '你是一位場景設計師，負責為故事中的新地點生成沉浸式的場景描述。',
            '描述應包含：視覺、聽覺、氣味、空間感、氛圍與潛在危險，控制在 150 字以內。',
            '風格需與世界觀一致，不加任何說明文字，直接輸出描述段落。',
            "【世界規則】\n{$setting}",
        ]);

        $messages = [
            [
                'role' => 'user',
                'text' => implode("\n\n", [
                    "地點名稱：{$locationName}",
                    "目前故事背景：{$worldContext}",
                    '請生成這個地點的場景描述。',
                ]),
            ],
        ];

        return $this->storyGemini->generate($systemPrompt, $messages);
    }

    /**
     * Generate an external event to break a story deadlock.
     *
     * Called when N consecutive rounds show no meaningful progress.
     * Uses the powerful state model — same LLM family as the State LLM.
     *
     * @param  array<int, array{name: string, description: string, holder: string|null}>  $items
     * @param  array<int, array{character: string, content: string}>  $recentSegments
     */
    public function generateExternalEvent(
        string $setting,
        string $worldState,
        array $items = [],
        array $recentSegments = [],
    ): string {
        $systemPrompt = implode("\n\n", [
            '你是故事世界的「命運之手」，負責在故事陷入停滯時引入外部事件，強制推進劇情。',
            '你的事件必須：',
            '1. 與世界觀一致，不違反世界規則',
            '2. 讓目前的角色無法繼續迴避衝突或維持現狀',
            '3. 控制在 100 字以內，以旁白口吻描述，不替任何角色做決定',
            '4. 可以是自然災害、陌生人闖入、重要道具消失、突發消息等',
            "【不可變世界規則】\n{$setting}\n【規則結束】",
        ]);

        $itemsList = empty($items)
            ? '（目前無道具）'
            : implode("\n", array_map(
                fn($item) => '- ' . $item['name'] . '（' . ($item['holder'] ?? '無人持有') . '）',
                $items,
            ));

        $recentText = empty($recentSegments)
            ? '（無近期段落）'
            : implode("\n\n", array_map(
                fn($seg) => "[{$seg['character']}] {$seg['content']}",
                $recentSegments,
            ));

        $messages = [
            [
                'role' => 'user',
                'text' => implode("\n\n", [
                    "【目前世界狀態】\n{$worldState}",
                    "【道具清單】\n{$itemsList}",
                    "【近期段落（故事已停滯）】\n{$recentText}",
                    '故事已停滯，請生成一個外部事件，讓各角色不得不做出反應。',
                ]),
            ],
        ];

        return $this->stateGemini->generate($systemPrompt, $messages);
    }

    private function buildCharacterPrompt(
        string $setting,
        string $worldState,
        string $characterName,
        string $characterPersona,
        array $items,
        string $contentRating,
        ?string $sceneDescription = null,
    ): string {
        $itemsList = empty($items)
            ? '（目前無道具）'
            : implode("\n", array_map(
                fn($item) => '- ' . $item['name'] . '（' . ($item['holder'] ?? '無人持有') . '）：' . $item['description'],
                $items,
            ));

        $contentRule = $contentRating === 'general'
            ? '若故事走向涉及成人內容，請以「[此處省略]」代替，並自然地將場景帶往下一個時間點繼續敘事。'
            : '可自由敘述所有內容。';

        $scenePart = $sceneDescription !== null
            ? "【當前場景】\n{$sceneDescription}"
            : null;

        return implode("\n\n", array_filter([
            "你正在扮演故事中的角色「{$characterName}」。",
            "【角色設定】\n{$characterPersona}",
            "【不可變世界規則】\n{$setting}",
            "【目前世界狀態】\n{$worldState}",
            $scenePart,
            "【道具清單】\n{$itemsList}",
            implode("\n", [
                '【規則】',
                '- 以第一人稱或第三人稱敘述均可，保持角色個性一致',
                '- 每次只寫 150 ～ 300 字，不要替其他角色做決定',
                '- 可以使用、傳遞或發現道具，但不能憑空創造不合理的物品',
                '- 不得違反世界規則，例如在無魔法的世界施展魔法',
                "- {$contentRule}",
            ]),
        ]));
    }
}

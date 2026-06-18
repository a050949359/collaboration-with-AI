<?php

namespace App\Enums;

/**
 * LLM「用途(use)」的單一來源。
 *
 * value 同時是:`config('services.llm.uses')` 的 key、`admin_settings.llm` 的 key、
 * `LlmManager::for()` 的用途名。**新增用途只需在此加一個 case(+ label)**;
 * config 的 env 預設為「可選覆蓋」(沒列就退回 gemini 預設)。
 *
 * label 為後台 System 設定頁的顯示文字(admin-only,非使用者 i18n,故放後端集中)。
 */
enum LlmUse: string
{
    case Story = 'story';
    case StoryState = 'story_state';
    case Character = 'character';
    case Resume = 'resume';        // About 履歷對話(chat 名稱保留給未來通用聊天)
    case Rag = 'rag';              // RAG 問答

    public function label(): string
    {
        return match ($this) {
            self::Story => '故事段落生成',
            self::StoryState => '世界狀態更新',
            self::Character => '角色生成',
            self::Resume => 'About 履歷對話',
            self::Rag => 'RAG 問答',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 供前端 System 設定頁渲染用途列表(取代寫死的 LLM_USES)。
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $u) => ['value' => $u->value, 'label' => $u->label()],
            self::cases(),
        );
    }
}

<?php

namespace App\Enums;

enum ArticleStyle: string
{
    case Practical = 'practical';
    case Narrative = 'narrative';
    case Journalistic = 'journalistic';
    case Casual = 'casual';

    public function label(): string
    {
        return match ($this) {
            self::Practical => '實用指南',
            self::Narrative => '敘事散文',
            self::Journalistic => '新聞報導',
            self::Casual => '輕鬆隨筆',
        };
    }

    public function instruction(): string
    {
        return match ($this) {
            self::Practical => 'practical guide with actionable tips',
            self::Narrative => 'narrative essay with storytelling flow',
            self::Journalistic => 'journalistic reporting, objective and factual',
            self::Casual => 'casual and friendly tone, conversational',
        };
    }
}

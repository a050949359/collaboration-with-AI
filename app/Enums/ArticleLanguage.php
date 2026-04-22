<?php

namespace App\Enums;

enum ArticleLanguage: string
{
    case TraditionalChinese = 'zh-TW';
    case SimplifiedChinese = 'zh-CN';
    case English = 'en';
    case Japanese = 'ja';

    public function label(): string
    {
        return match ($this) {
            self::TraditionalChinese => '繁體中文',
            self::SimplifiedChinese => '简体中文',
            self::English => 'English',
            self::Japanese => '日本語',
        };
    }

    public function instruction(): string
    {
        return match ($this) {
            self::TraditionalChinese => 'Traditional Chinese (Taiwan)',
            self::SimplifiedChinese => 'Simplified Chinese (Mainland)',
            self::English => 'English',
            self::Japanese => 'Japanese',
        };
    }
}

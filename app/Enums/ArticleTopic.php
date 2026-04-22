<?php

namespace App\Enums;

enum ArticleTopic: string
{
    case Travel = 'travel';
    case Food = 'food';
    case Technology = 'technology';
    case Lifestyle = 'lifestyle';
    case Nature = 'nature';
    case Culture = 'culture';
    case Business = 'business';
    case Health = 'health';

    public function label(): string
    {
        return match ($this) {
            self::Travel => '旅遊',
            self::Food => '美食',
            self::Technology => '科技',
            self::Lifestyle => '生活風格',
            self::Nature => '自然',
            self::Culture => '文化',
            self::Business => '商業',
            self::Health => '健康',
        };
    }

    public function imageStylePrefix(): string
    {
        return match ($this) {
            self::Travel => 'editorial travel photography, cinematic wide shot,',
            self::Food => 'food photography, natural light, appetizing,',
            self::Technology => 'modern tech product shot, clean minimalist,',
            self::Lifestyle => 'lifestyle photography, warm tones, authentic,',
            self::Nature => 'nature photography, golden hour, vivid colors,',
            self::Culture => 'documentary photography, rich cultural detail,',
            self::Business => 'professional corporate photography, clean background,',
            self::Health => 'wellness photography, bright and clean, natural,',
        };
    }
}

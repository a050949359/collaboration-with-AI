<?php

namespace App\Enums;

enum StoryCharacterType: string
{
    case Llm    = 'llm';
    case Player = 'player';
    case Npc    = 'npc';
}

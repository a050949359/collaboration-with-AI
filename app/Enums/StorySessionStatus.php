<?php

namespace App\Enums;

enum StorySessionStatus: string
{
    case Active    = 'active';
    case Paused    = 'paused';
    case Completed = 'completed';
}

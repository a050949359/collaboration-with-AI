<?php

namespace App\Enums;

enum RoomType: string
{
    case Single  = 'single';
    case Double  = 'double';
    case Twin    = 'twin';
    case Suite   = 'suite';
    case Deluxe  = 'deluxe';
}
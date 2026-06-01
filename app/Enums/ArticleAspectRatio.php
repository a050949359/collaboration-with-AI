<?php

namespace App\Enums;

enum ArticleAspectRatio: string
{
    case R16x9 = '16:9';
    case R1x1  = '1:1';
    case R3x4  = '3:4';
    case R4x3  = '4:3';
    case R9x16 = '9:16';
}

<?php

namespace App\Enums;

enum AirportType: string
{
    case LargeAirport  = 'large_airport';
    case MediumAirport = 'medium_airport';
    case SmallAirport  = 'small_airport';
    case Heliport      = 'heliport';
    case SeaplaneBase  = 'seaplane_base';
    case Closed        = 'closed';

    /** 附近搜尋可用的類型（排除已關閉） */
    public static function searchable(): array
    {
        return array_column(
            array_filter(self::cases(), fn($c) => $c !== self::Closed),
            'value'
        );
    }
}

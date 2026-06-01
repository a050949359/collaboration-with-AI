<?php

namespace App\Enums;

enum PassengerFilter: string
{
    case NoBooking     = 'no_booking';
    case CompanionOnly = 'companion_only';
    case Booker        = 'booker';
}

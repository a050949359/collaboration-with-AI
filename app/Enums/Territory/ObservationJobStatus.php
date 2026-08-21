<?php

namespace App\Enums\Territory;

enum ObservationJobStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Success = 'success';
    case Failed = 'failed';
}

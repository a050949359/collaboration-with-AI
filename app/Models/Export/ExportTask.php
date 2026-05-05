<?php

namespace App\Models\Export;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'type',
    'params',
    'status',
    'file_path',
    'error_message',
])]
class ExportTask extends Model
{
    //
}

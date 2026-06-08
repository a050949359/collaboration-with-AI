<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MicroHost\MicroHostStatus;
use Illuminate\Http\JsonResponse;

class MicroHostController extends Controller
{
    public function status(MicroHostStatus $status): JsonResponse
    {
        return response()->json($status->full());
    }
}

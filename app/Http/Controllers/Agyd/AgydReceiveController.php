<?php

namespace App\Http\Controllers\Agyd;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AgydReceiveController extends Controller
{
    public function upload(Request $request, string $taskId): JsonResponse
    {
        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $taskId)) {
            return response()->json(['error' => 'invalid task_id'], 400);
        }

        $secret = config('agyd.secret');
        if (empty($secret) || $request->bearerToken() !== $secret) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $request->validate(['file' => 'required|file|mimes:zip|max:51200']);

        $zip  = $request->file('file');
        $dest = config('agyd.storage_path') . '/' . $taskId;

        // 清掉舊的再解壓，避免殘留
        Storage::disk('public')->deleteDirectory($dest);

        $za = new \ZipArchive();
        if ($za->open($zip->getRealPath()) !== true) {
            return response()->json(['error' => 'invalid zip'], 422);
        }

        $absPath = Storage::disk('public')->path($dest);
        $za->extractTo($absPath);
        $za->close();

        return response()->json([
            'task_id' => $taskId,
            'path'    => 'storage/' . $dest,
        ]);
    }
}

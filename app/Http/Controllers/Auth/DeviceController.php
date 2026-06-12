<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $devices = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn(PersonalAccessToken $token) => [
                'id'           => $token->id,
                'name'         => $token->name,
                'device_id'    => $token->device_id,
                'last_used_at' => $token->last_used_at,
                'expires_at'   => $token->expires_at,
                'created_at'   => $token->created_at,
                'is_current'   => $token->id === $request->user()->currentAccessToken()->id,
            ]);

        return response()->json($devices);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $token = $request->user()->tokens()->find($id);

        if (!$token) {
            return response()->json(['message' => '裝置不存在'], 404);
        }

        $token->delete();

        return response()->json(['message' => '已撤銷裝置登入']);
    }
}

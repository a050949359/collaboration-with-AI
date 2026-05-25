<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareToken\CheckShareTokenRequest;
use App\Http\Requests\ShareToken\StoreShareTokenRequest;
use App\Models\ShareToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ShareTokenController extends Controller
{
    public function index(): JsonResponse
    {
        $tokens = ShareToken::with('creator:id,name')
            ->orderByDesc('created_at')
            ->get(['id', 'scope', 'max_uses', 'uses_count', 'note', 'expires_at', 'line_user_id', 'created_by', 'created_at']);

        return response()->json($tokens);
    }

    public function store(StoreShareTokenRequest $request): JsonResponse
    {
        $data = $request->validated();

        $raw  = Str::random(48);
        $hash = hash('sha256', $raw);

        $token = ShareToken::create([
            'token'      => $hash,
            'scope'      => $data['scope'],
            'max_uses'   => $data['max_uses'] ?? null,
            'note'       => $data['note'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'id'         => $token->id,
            'raw_token'  => $raw,
            'scope'      => $token->scope,
            'max_uses'   => $token->max_uses,
            'note'       => $token->note,
            'expires_at' => $token->expires_at,
            'created_at' => $token->created_at,
        ], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $token = ShareToken::findOrFail($id);
        $token->delete();

        return response()->json(['message' => '已刪除']);
    }

    public function check(CheckShareTokenRequest $request): JsonResponse
    {
        $data = $request->validated();

        $shareToken = ShareToken::findByRaw($data['token']);

        if (! $shareToken || $shareToken->scope !== $data['scope'] || ! $shareToken->isValid()) {
            return response()->json(['valid' => false, 'message' => '連結無效或次數已用盡'], 403);
        }

        return response()->json(['valid' => true]);
    }
}

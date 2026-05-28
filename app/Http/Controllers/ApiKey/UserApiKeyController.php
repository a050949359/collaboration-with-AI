<?php

namespace App\Http\Controllers\ApiKey;

use App\Enums\ApiKeyScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApiKey\CreateUserApiKeyRequest;
use App\Models\ApiKey\UserApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class UserApiKeyController extends Controller
{
    // 取得目前登入者的所有 API 金鑰
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $keys = UserApiKey::where('user_id', $user->id)
            ->get(['id', 'name', 'scopes', 'revoked_at', 'created_at']);
        return response()->json($keys);
    }

    // 建立新金鑰（回傳明文）
    public function store(CreateUserApiKeyRequest $request): JsonResponse
    {
        $user      = Auth::user();
        $validated = $request->validated();
        $name      = $validated['name'] ?? 'api-key';
        $publicKey = $validated['publicKey'];
        $raw = Str::random(48);
        $hash = hash('sha256', $raw);
        $scopes = $validated['scopes'] ?? null;

        if ($scopes) {
            $isAdmin = Auth::user()->isAdmin();
            foreach ($scopes as $scope) {
                $scopeEnum = ApiKeyScope::from($scope);
                if ($scopeEnum->adminOnly() && ! $isAdmin) {
                    return response()->json(['message' => "Scope '{$scope}' requires admin."], 403);
                }
            }
        }

        $apiKey = UserApiKey::create([
            'user_id'      => $user->id,
            'name'         => $name,
            'scopes'       => $scopes ?: null,
            'api_key_hash' => $hash,
        ]);
        $encrypted = null;
        // PHP 8.1+ 支援 OAEP hash 選擇，與前端 Web Crypto API (SHA-256) 對齊
        if (openssl_public_encrypt(
            $raw,
            $encrypted,
            $publicKey,
            OPENSSL_PKCS1_OAEP_PADDING,
        )) {
            $apiKeyValue = base64_encode($encrypted);
        } else {
            return response()->json(['message' => '公鑰加密失敗'], 400);
        }
        return response()->json([
            'api_key' => $apiKeyValue, // 只顯示一次
            'id' => $apiKey->id,
        ]);
    }

    // 切換撤銷狀態
    public function update(Request $request, $id): JsonResponse
    {
        $user = Auth::user();
        $apiKey = UserApiKey::where('user_id', $user->id)->findOrFail($id);
        $apiKey->revoked_at = $request->boolean('revoked') ? now() : null;
        $apiKey->save();
        return response()->json(['message' => $apiKey->revoked_at ? 'API 金鑰已撤銷' : 'API 金鑰已復原']);
    }

    // 刪除金鑰
    public function destroy($id): JsonResponse
    {
        $user = Auth::user();
        $apiKey = UserApiKey::where('user_id', $user->id)->findOrFail($id);
        $apiKey->delete();
        return response()->json(['message' => 'API 金鑰已刪除']);
    }
}

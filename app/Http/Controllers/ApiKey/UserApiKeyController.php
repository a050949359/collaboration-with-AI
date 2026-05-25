<?php

namespace App\Http\Controllers\ApiKey;

use App\Http\Controllers\Controller;
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
        $keys = UserApiKey::where('user_id', $user->id)->get();
        return response()->json($keys);
    }

    // 建立新金鑰（回傳明文）
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        $type = $request->input('type', 'default');
        $publicKey = $request->input('publicKey');
        if (!$publicKey) {
            return response()->json(['message' => '缺少 publicKey，無法加密 API 金鑰'], 400);
        }
        $raw = Str::random(48);
        $hash = hash('sha256', $raw);
        $apiKey = UserApiKey::create([
            'user_id' => $user->id,
            'type' => $type,
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

    // 撤銷金鑰
    public function revoke($id): JsonResponse
    {
        $user = Auth::user();
        $apiKey = UserApiKey::where('user_id', $user->id)->findOrFail($id);
        $apiKey->revoked_at = now();
        $apiKey->save();
        return response()->json(['message' => 'API 金鑰已撤銷']);
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

<?php

namespace App\Http\Controllers\Line;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\LineBotHmac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LineFriendController extends Controller
{
    public function add(Request $request): JsonResponse
    {
        if ($authError = $this->authorizeInternalRequest($request)) {
            return $authError;
        }

        $payload = $request->validate([
            'line_user_id' => ['required', 'string', 'max:64'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'avatar_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $lineUserId = $payload['line_user_id'];
        $displayName = trim((string) ($payload['display_name'] ?? ''));

        $result = DB::transaction(function () use ($lineUserId, $displayName, $payload): array {
            $social = SocialAccount::query()
                ->where('provider', 'line')
                ->where('provider_user_id', $lineUserId)
                ->first();

            $created = false;

            if ($social) {
                $user = $social->user;
            } else {
                $created = true;
                $defaultName = $displayName !== '' ? $displayName : 'LINE User '.Str::upper(Str::substr($lineUserId, -6));

                $user = User::create([
                    'name' => Str::limit($defaultName, 255, ''),
                    'email' => 'line_'.Str::lower($lineUserId).'@line.local',
                    'password' => Hash::make(Str::random(40)),
                    'role' => 'user',
                ]);
            }

            $social = $user->socialAccounts()->updateOrCreate(
                ['provider' => 'line', 'provider_user_id' => $lineUserId],
                [
                    'avatar_url' => $payload['avatar_url'] ?? null,
                ]
            );

            if ($displayName !== '' && str_starts_with($user->name, 'LINE User ')) {
                $user->name = Str::limit($displayName, 255, '');
                $user->save();
            }

            return [
                'created' => $created,
                'user_id' => $user->id,
                'social_account_id' => $social->id,
            ];
        });

        return response()->json([
            'message' => $result['created'] ? 'LINE 好友已建立帳號' : 'LINE 好友已更新綁定',
            ...$result,
        ]);
    }

    public function remove(Request $request): JsonResponse
    {
        if ($authError = $this->authorizeInternalRequest($request)) {
            return $authError;
        }

        $payload = $request->validate([
            'line_user_id' => ['required', 'string', 'max:64'],
        ]);

        $social = SocialAccount::query()
            ->where('provider', 'line')
            ->where('provider_user_id', $payload['line_user_id'])
            ->first();

        if (!$social) {
            return response()->json([
                'message' => '找不到 LINE 綁定資料',
                'removed' => false,
            ]);
        }

        $social->delete();

        return response()->json([
            'message' => 'LINE 綁定已移除',
            'removed' => true,
        ]);
    }

    private function authorizeInternalRequest(Request $request): ?JsonResponse
    {
        $expected = (string) config('services.line_bot.internal_api_key', '');
        $provided = (string) $request->header('X-Line-Bot-Key', '');

        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Unauthorized internal request',
            ], 401);
        }

        $hmac = app(LineBotHmac::class);

        if (!$hmac->verifyInbound($request)) {
            return response()->json([
                'message' => 'Unauthorized internal request',
            ], 401);
        }

        return null;
    }
}

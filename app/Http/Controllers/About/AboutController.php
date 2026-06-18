<?php

namespace App\Http\Controllers\About;

use App\Http\Controllers\Controller;
use App\Http\Requests\About\AskRequest;
use App\Services\About\ResumeChatService;
use App\Services\AI\AIServiceException;
use Illuminate\Http\JsonResponse;

class AboutController extends Controller
{
    public function __construct(private readonly ResumeChatService $resumeChat) {}

    public function ask(AskRequest $request): JsonResponse
    {
        try {
            $reply = $this->resumeChat->chat(
                message: $request->string('message')->toString(),
                history: $request->input('history', []),
            );

            // 成功回覆後才扣次數（登入者無 share_token，這裡為 null → no-op）
            $request->attributes->get('share_token')?->incrementUses();

            return response()->json(['reply' => $reply]);
        } catch (AIServiceException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }
}

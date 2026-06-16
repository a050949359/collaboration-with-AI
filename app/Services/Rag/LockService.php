<?php

namespace App\Services\Rag;

use App\Models\Rag\Document;
use App\Models\Rag\Lock;
use App\Services\AI\AIServiceException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 草稿文件的 per-document 編輯鎖(租約)。
 *
 * 兩層權限的「臨時權限」層:身分由 MCP key / session 決定(Auth::id()),
 * 這裡只管「同一時間誰握有這份草稿」。lock_token 是 capability,前端與其 LLM 共用。
 * 過期(expires_at < now)視為未鎖,任何有權者可重新取得。
 */
class LockService
{
    private function ttl(): int
    {
        return (int) config('rag.lock.ttl', 1800);
    }

    /**
     * 取得/續用鎖。鎖空閒或已過期或本來就是自己 → 成功並回 lock_token;
     * 被他人持有且未過期 → 擲例外。
     */
    public function acquire(Document $document, int $userId): Lock
    {
        $existing = $document->lock()->first();

        if ($existing && ! $existing->isExpired() && $existing->locked_by !== $userId) {
            throw new AIServiceException('此文件正由其他使用者編輯中。');
        }

        // 自己原本就持有 → 沿用同一個 token;否則發新 token
        $token = ($existing && $existing->locked_by === $userId)
            ? $existing->lock_token
            : Str::random(48);

        return Lock::updateOrCreate(
            ['document_id' => $document->id],
            [
                'locked_by' => $userId,
                'lock_token' => $token,
                'expires_at' => Carbon::now()->addSeconds($this->ttl()),
            ],
        );
    }

    /**
     * 驗證一次編輯/commit:token 正確、未過期、且持有者就是這位使用者。
     * 通過會順手續租(編輯即延長租約)。
     */
    public function verify(Document $document, string $lockToken, int $userId): Lock
    {
        $lock = $document->lock()->first();

        if (! $lock || $lock->isExpired()) {
            throw new AIServiceException('編輯鎖不存在或已過期,請重新上鎖。');
        }
        if ($lock->locked_by !== $userId) {
            throw new AIServiceException('編輯鎖由其他使用者持有。');
        }
        if (! hash_equals($lock->lock_token, $lockToken)) {
            throw new AIServiceException('lock_token 不正確。');
        }

        $lock->update(['expires_at' => Carbon::now()->addSeconds($this->ttl())]);

        return $lock;
    }

    /**
     * 以 lock_token 反查鎖(交接用:前端上鎖拿 token、貼給 Claude,Claude 只憑 token 接手)。
     * 驗未過期 + 持有者一致,通過順手續租,回傳鎖(可由 ->document 取草稿)。
     */
    public function resolveByToken(string $lockToken, int $userId): Lock
    {
        $lock = Lock::where('lock_token', $lockToken)->first();

        if (! $lock || $lock->isExpired()) {
            throw new AIServiceException('lock_token 無效或已過期。');
        }
        if ($lock->locked_by !== $userId) {
            throw new AIServiceException('此 lock_token 非你持有。');
        }

        $lock->update(['expires_at' => Carbon::now()->addSeconds($this->ttl())]);

        return $lock;
    }

    /**
     * 釋放鎖(需為持有者)。
     */
    public function release(Document $document, string $lockToken, int $userId): void
    {
        $lock = $document->lock()->first();
        if (! $lock) {
            return;
        }
        if ($lock->locked_by !== $userId || ! hash_equals($lock->lock_token, $lockToken)) {
            throw new AIServiceException('只有鎖的持有者能解鎖。');
        }

        $lock->delete();
    }

    /**
     * 目前鎖狀態(供前端顯示「編輯中」)。回 null 表示空閒。
     *
     * @return array{locked_by: int, expires_at: string}|null
     */
    public function status(Document $document): ?array
    {
        $lock = $document->lock()->first();
        if (! $lock || $lock->isExpired()) {
            return null;
        }

        return [
            'locked_by' => $lock->locked_by,
            'expires_at' => $lock->expires_at->toIso8601String(),
        ];
    }
}

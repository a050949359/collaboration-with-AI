<?php

namespace App\Models;

use DateTimeInterface;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Support\AvatarGenerator;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    protected $appends = ['avatar', 'has_google_account'];
    public function getHasGoogleAccountAttribute(): bool
    {
        return $this->socialAccounts()->where('provider', 'google')->exists();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function passwordHistories(): HasMany
    {
        return $this->hasMany(PasswordHistory::class)->latest('created_at');
    }

    /** 密碼歷史保留筆數（單一來源，供改密/重設共用）。 */
    public const PASSWORD_HISTORY_LIMIT = 5;

    /**
     * 新密碼（明文）是否與目前密碼或最近 PASSWORD_HISTORY_LIMIT 筆相同。
     */
    public function passwordUsedRecently(string $plain): bool
    {
        // 先比目前密碼：history 可能未含目前密碼（註冊未寫入 / legacy 轉移）。
        if ($this->password && Hash::check($plain, $this->password)) {
            return true;
        }

        foreach ($this->passwordHistories()->take(self::PASSWORD_HISTORY_LIMIT)->get() as $history) {
            // 目前密碼通常是 history 第一筆，已於上方比過，跳過以免重複跑 bcrypt。
            if ($history->password_hash === $this->password) {
                continue;
            }
            if (Hash::check($plain, $history->password_hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 記錄一筆密碼歷史（傳入已雜湊值），並修剪至最近 PASSWORD_HISTORY_LIMIT 筆。
     */
    public function recordPasswordHistory(string $hash): void
    {
        $this->passwordHistories()->create(['password_hash' => $hash]);

        $stale = $this->passwordHistories()->pluck('id')->skip(self::PASSWORD_HISTORY_LIMIT);
        if ($stale->isNotEmpty()) {
            $this->passwordHistories()->whereIn('id', $stale)->delete();
        }
    }

    protected function avatar(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $socialAvatar = $this->socialAccounts()
                    ->whereNotNull('avatar_url')
                    ->latest('id')
                    ->value('avatar_url');

                if (is_string($socialAvatar) && $socialAvatar !== '') {
                    return $socialAvatar;
                }

                return AvatarGenerator::defaultFor($this->name, $this->email, $this->getKey());
            },
        );
    }

    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null, ?string $deviceId = null): NewAccessToken
    {
        $plainTextToken = $this->generateTokenString();

        $token = $this->tokens()->create([
            'name'       => $name,
            'token'      => hash('sha256', $plainTextToken),
            'abilities'  => $abilities,
            'expires_at' => $expiresAt ?? now()->addDays(90),
            'device_id'  => $deviceId,
        ]);

        return new NewAccessToken($token, $token->getKey().'|'.$plainTextToken);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id'   => $this->getKey(),
                'hash' => sha1($this->getEmailForVerification()),
            ]
        );

        $this->notify(new VerifyEmailNotification($url));
    }
}

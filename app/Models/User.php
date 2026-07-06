<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Support\AvatarGenerator;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use DateTimeInterface;
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

/**
 * @property CarbonInterface|null $email_verified_at
 * @property CarbonInterface|null $locked_until
 * @property CarbonInterface|null $password_changed_at
 * @property CarbonInterface|null $two_factor_confirmed_at
 * @property string|null $two_factor_secret
 * @property array<int, string>|null $two_factor_recovery_codes
 * @property-read bool $two_factor_enabled
 */
#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $appends = ['avatar', 'has_google_account', 'two_factor_enabled'];

    public function getHasGoogleAccountAttribute(): bool
    {
        return $this->socialAccounts()->where('provider', 'google')->exists();
    }

    public function getTwoFactorEnabledAttribute(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /** 已產生 secret 但尚未通過 OTP 確認（綁定進行中）。 */
    public function hasPendingTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at === null;
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
            'two_factor_secret' => 'encrypted',
            // 存 bcrypt hash 陣列（不可逆，故不需再 encrypted）
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** @return HasMany<PasswordHistory, $this> */
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

        // 保留最近 PASSWORD_HISTORY_LIMIT 筆（relation 已 latest 排序），其餘刪除。
        $keepIds = $this->passwordHistories()->take(self::PASSWORD_HISTORY_LIMIT)->pluck('id');
        $this->passwordHistories()->whereNotIn('id', $keepIds)->delete();
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
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $expiresAt ?? now()->addDays(90),
            'device_id' => $deviceId,
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
                'id' => $this->getKey(),
                'hash' => sha1($this->getEmailForVerification()),
            ]
        );

        $this->notify(new VerifyEmailNotification($url));
    }
}

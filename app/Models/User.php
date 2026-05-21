<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\URL;
use App\Support\AvatarGenerator;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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

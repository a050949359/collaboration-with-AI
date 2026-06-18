<?php

namespace App\Models\ApiKey;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property array|null $scopes
 * @property string $api_key_hash
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['user_id', 'name', 'scopes', 'api_key_hash', 'revoked_at'])]
class UserApiKey extends Model
{
    /** @use HasFactory<UserApiKey> */
    use HasFactory;

    protected $table = 'user_api_keys';

    protected $hidden = ['api_key_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}

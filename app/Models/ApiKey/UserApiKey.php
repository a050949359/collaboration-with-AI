<?php

namespace App\Models\ApiKey;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $api_key_hash
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
#[Fillable(['user_id', 'name', 'type', 'api_key_hash', 'revoked_at'])]
class UserApiKey extends Model
{
    /** @use HasFactory<UserApiKey> */
    use HasFactory;

    protected $table = 'user_api_keys';

    protected $hidden = ['api_key_hash'];


    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}

<?php

namespace App\Models\ApiKey;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * @property int $id
 * @property int $user_id
 * @property array|null $scopes
 * @property string $api_key_hash
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
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
            'scopes'     => 'array',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function hasScope(string $scope): bool
    {
        if ($this->scopes === null) return true; // null = 無限制
        return \in_array($scope, $this->scopes);
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

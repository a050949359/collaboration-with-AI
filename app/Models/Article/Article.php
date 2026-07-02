<?php

namespace App\Models\Article;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<string>|null $tags cast 為 array（larastan 讀不到 casts() 的 array cast，需明標）
 */
#[Fillable([
    'user_id',
    'created_via',
    'title',
    'category',
    'prompt',
    'content',
    'summary',
    'tags',
    'image_path',
    'image_url',
    'content_status',
    'image_status',
    'content_error',
    'image_error',
    'content_generated_at',
    'image_generated_at',
])]
class Article extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'created_via' => 'string',
            'content_generated_at' => 'datetime',
            'image_generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function comments()
    {
        return $this->hasMany(ArticleComment::class);
    }
}

<?php

namespace App\Models\Article;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $article_id
 * @property int|null $user_id
 * @property string|null $guest_id
 * @property string|null $guest_name
 * @property int|null $parent_id
 * @property string $body
 * @property bool|null $can_edit Set per-request by ArticleCommentController::index()
 */
#[Fillable([
    'article_id',
    'user_id',
    'guest_name',
    'guest_id',
    'parent_id',
    'body',
])]
#[Hidden([
    'guest_id',
])]
class ArticleComment extends Model
{
    use HasFactory, SoftDeletes;

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ArticleComment, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ArticleComment::class, 'parent_id');
    }

    /** @return HasMany<ArticleComment, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(ArticleComment::class, 'parent_id');
    }
}

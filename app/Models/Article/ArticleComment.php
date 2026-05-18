<?php

namespace App\Models\Article;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;

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

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ArticleComment::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ArticleComment::class, 'parent_id');
    }
}
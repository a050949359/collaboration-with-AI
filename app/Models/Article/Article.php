<?php

namespace App\Models\Article;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function comments()
    {
        return $this->hasMany(ArticleComment::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskItem extends Model
{
    protected $fillable = ['content', 'is_done', 'sort'];

    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'sort'    => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}

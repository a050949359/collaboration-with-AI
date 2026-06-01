<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string|null $project
 * @property TaskStatus $status
 * @property int $sort
 * @property int $created_by
 */
class Task extends Model
{
    protected $fillable = ['title', 'description', 'project', 'status', 'sort'];

    protected function casts(): array
    {
        return [
            'sort'   => 'integer',
            'status' => TaskStatus::class,
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(TaskItem::class)->orderBy('sort');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

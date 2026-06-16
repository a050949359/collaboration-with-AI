<?php

namespace App\Models\Rag;

use App\Enums\Rag\DocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $knowledge_base_id
 * @property string $drive_file_id
 * @property string $name
 * @property string $mime_type
 * @property string|null $modified_time
 * @property DocumentStatus $status
 * @property Carbon|null $committed_at
 */
class Document extends Model
{
    protected $table = 'rag_documents';

    protected $fillable = [
        'knowledge_base_id', 'drive_file_id', 'name', 'mime_type', 'modified_time', 'status', 'committed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'committed_at' => 'datetime',
        ];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class, 'document_id')->orderBy('chunk_index');
    }

    public function lock(): HasOne
    {
        return $this->hasOne(Lock::class, 'document_id');
    }
}

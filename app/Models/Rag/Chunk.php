<?php

namespace App\Models\Rag;

use App\Enums\Rag\ChunkStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $document_id
 * @property int $chunk_index
 * @property string $content
 * @property string|null $context
 * @property string $content_hash
 * @property array<int, float>|null $embedding
 * @property Carbon|null $embedded_at
 * @property ChunkStatus $status
 */
class Chunk extends Model
{
    protected $table = 'rag_chunks';

    protected $fillable = [
        'document_id', 'chunk_index', 'content', 'context', 'content_hash', 'embedding', 'embedded_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'embedding' => 'array',
            'embedded_at' => 'datetime',
            'status' => ChunkStatus::class,
        ];
    }

    /**
     * 向量要 embed 的完整文字:有 context 前綴就黏上(Contextual Retrieval)。
     */
    public function embeddableText(): string
    {
        $context = trim((string) $this->context);

        return $context === '' ? $this->content : $context."\n\n".$this->content;
    }

    /**
     * 給 vecgen 的 document id:`<drive_file_id>#<chunk_index>`,決定性、可覆蓋。
     */
    public function vectorId(string $driveFileId): string
    {
        return $driveFileId.'#'.$this->chunk_index;
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}

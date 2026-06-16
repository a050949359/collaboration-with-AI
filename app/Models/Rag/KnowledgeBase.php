<?php

namespace App\Models\Rag;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $embedding_model
 * @property int $dimensions
 */
class KnowledgeBase extends Model
{
    protected $table = 'rag_knowledge_bases';

    protected $fillable = ['user_id', 'name', 'embedding_model', 'dimensions'];

    protected function casts(): array
    {
        return [
            'dimensions' => 'integer',
        ];
    }

    /**
     * collection 名:`<庫名>__<模型>__<維度>`。換模型/維度自動隔離向量空間。
     */
    public function collectionName(): string
    {
        return sprintf('%s__%s__%d', $this->name, $this->embedding_model, $this->dimensions);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'knowledge_base_id');
    }
}

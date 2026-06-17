<?php

namespace App\Services\Rag;

use App\Enums\Rag\ChunkStatus;
use App\Enums\Rag\DocumentStatus;
use App\Models\Rag\Chunk;
use App\Models\Rag\Document;
use App\Models\Rag\KnowledgeBase;
use App\Services\AI\AIServiceException;
use App\Services\AI\Contracts\TextEmbedding;
use App\Services\AI\LlmManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * RAG 協調層:Drive 讀取 → 切塊 → 草稿(DB)→ commit(embed + vecgen)→ 檢索。
 *
 * 草稿(rag_chunks)是可編輯的單一真相,存 content_hash + embedding(向量快取);
 * vecgen(chromem-go)只放「已 commit」的向量,純檢索。預覽/去重在 PHP 算 cosine,
 * 不污染正式庫。collection 命名由 KnowledgeBase 決定(含模型+維度,隔離向量空間)。
 */
class RagService
{
    public function __construct(
        private readonly TextEmbedding $embedder,
        private readonly Chunker $chunker,
        private readonly DriveReader $drive,
        private readonly LlmManager $llm,
    ) {}

    // ── 草稿 ────────────────────────────────────────────────────────────

    /**
     * 從一個 Drive 檔產生/更新草稿:抽文字 → 遞迴切塊 → 寫入 rag_chunks(draft)。
     *
     * 重新 propose(檔更新/重切)時:依 content_hash 保留未變塊的向量快取,
     * 並回傳與舊塊的 diff(未變/新增/刪除)供「取代確認」用。
     *
     * 預設「已有草稿就直接載入、不重切」(避免無聲覆蓋編輯/手填 context);
     * 要強制重新從 Drive 抽取重切(如檔案已更新)須帶 $force=true。
     *
     * @param  array{id: string, name: string, mime_type: string, modified_time?: string}  $file
     * @return array{document: Document, chunks: int, diff: array{unchanged: int, added: int, removed: int}, loaded: bool}
     */
    public function proposeDraft(KnowledgeBase $kb, array $file, bool $force = false): array
    {
        $existing = Document::where('knowledge_base_id', $kb->id)
            ->where('drive_file_id', $file['id'])
            ->first();

        // 已有草稿且非強制 → 載入既有,不重切(不覆蓋使用者編輯)
        if ($existing && ! $force && $existing->chunks()->exists()) {
            $n = $existing->chunks()->count();

            return [
                'document' => $existing,
                'chunks' => $n,
                'diff' => ['unchanged' => $n, 'added' => 0, 'removed' => 0],
                'loaded' => true,
            ];
        }

        $text = $this->drive->extract($file['id'], $file['mime_type']);
        if (trim($text) === '') {
            throw new AIServiceException("檔案無可萃取文字: {$file['name']}");
        }

        $pieces = $this->chunker->chunk($text);

        return DB::transaction(function () use ($kb, $file, $pieces) {
            $document = Document::firstOrNew([
                'knowledge_base_id' => $kb->id,
                'drive_file_id' => $file['id'],
            ]);
            $document->fill([
                'name' => $file['name'],
                'mime_type' => $file['mime_type'],
                'modified_time' => $file['modified_time'] ?? null,
                // 曾落庫(committed_at 有值,含 committed/dirty)再重切 → dirty;從沒落庫過 → draft
                'status' => $document->committed_at !== null
                    ? DocumentStatus::Dirty
                    : DocumentStatus::Draft,
            ])->save();

            // 舊塊:hash → 向量快取,供未變塊重用;同時算 diff
            $old = $document->chunks()->get();
            $oldByHash = $old->keyBy('content_hash');
            $document->chunks()->delete();

            $newHashes = [];
            foreach ($pieces as $i => $content) {
                $hash = hash('sha256', $content);
                $newHashes[$hash] = true;
                $cached = $oldByHash->get($hash);

                Chunk::create([
                    'document_id' => $document->id,
                    'chunk_index' => $i,
                    'content' => $content,
                    'content_hash' => $hash,
                    // 內容沒變 → 沿用舊向量(commit 不必重 embed)
                    'embedding' => $cached?->embedding,
                    'embedded_at' => $cached?->embedded_at,
                    'status' => ChunkStatus::Draft,
                ]);
            }

            $unchanged = count(array_intersect_key($newHashes, $oldByHash->toArray()));

            return [
                'document' => $document->refresh(),
                'chunks' => count($pieces),
                'diff' => [
                    'unchanged' => $unchanged,
                    'added' => count($newHashes) - $unchanged,
                    'removed' => $old->count() - $unchanged,
                ],
                'loaded' => false,
            ];
        });
    }

    /**
     * 列全域 Drive 檔,標出相對此知識庫的狀態:new / in_kb / changed。
     *
     * @return array<int, array{id: string, name: string, mime_type: string, modified_time: string, status: string, document_id: int|null}>
     */
    public function listDriveFiles(KnowledgeBase $kb): array
    {
        $docs = $kb->documents()->get()->keyBy('drive_file_id');

        return collect($this->drive->list())->map(function ($f) use ($docs) {
            $doc = $docs->get($f['id']);
            $status = 'new';
            if ($doc) {
                $status = ($doc->modified_time && $f['modified_time'] && $f['modified_time'] !== $doc->modified_time)
                    ? 'changed'
                    : 'in_kb';
            }

            return [...$f, 'status' => $status, 'document_id' => $doc?->id];
        })->all();
    }

    /**
     * 以 Drive file_id 產生草稿(自動解析檔案後設資料)。
     *
     * @return array{document: Document, chunks: int, diff: array{unchanged: int, added: int, removed: int}}
     */
    public function proposeDraftByFileId(KnowledgeBase $kb, string $driveFileId, bool $force = false): array
    {
        $file = collect($this->drive->list())->firstWhere('id', $driveFileId);
        if (! $file) {
            throw new AIServiceException("Drive 找不到此檔或不支援: {$driveFileId}");
        }

        return $this->proposeDraft($kb, $file, $force);
    }

    /**
     * 落庫:embed 尚無向量的塊(快取重用)→ vecgen 取代該檔向量 → 標 committed。
     *
     * @return array{collection: string, chunks: int, embedded: int}
     */
    public function commit(Document $document): array
    {
        $kb = $document->knowledgeBase;
        $collection = $kb->collectionName();

        $embedded = $this->ensureEmbeddings($document);

        $chunks = $document->chunks()->get();
        if ($chunks->isEmpty()) {
            throw new AIServiceException('沒有可落庫的塊。');
        }

        $payload = $chunks->map(fn (Chunk $c) => [
            'id' => $c->vectorId($document->drive_file_id),
            'content' => $c->content,
            'embedding' => $c->embedding,
            'metadata' => [
                'file_id' => $document->drive_file_id,
                'file_name' => $document->name,
                'chunk_index' => $c->chunk_index,
            ],
        ])->all();

        // 先清掉此檔舊向量(處理塊數/索引變動),再寫新塊
        $this->vecgen('delete', $collection, ['where' => ['file_id' => $document->drive_file_id]]);
        $this->vecgen('upsert', $collection, ['documents' => $payload]);

        $document->chunks()->update(['status' => ChunkStatus::Committed]);
        $document->update([
            'status' => DocumentStatus::Committed,
            'committed_at' => Carbon::now(),
        ]);

        return ['collection' => $collection, 'chunks' => $chunks->count(), 'embedded' => $embedded];
    }

    /**
     * 確保所有塊都有向量:只 embed 缺向量的(hash 快取重用),批次寫回。回實際 embed 的塊數。
     */
    private function ensureEmbeddings(Document $document): int
    {
        $missing = $document->chunks()->whereNull('embedding')->get();
        if ($missing->isEmpty()) {
            return 0;
        }

        $vectors = $this->embedder->embedBatch(
            $missing->map(fn (Chunk $c) => $c->embeddableText())->all(),
            ['task_type' => 'RETRIEVAL_DOCUMENT'],
        );

        foreach ($missing as $i => $chunk) {
            $chunk->update([
                'embedding' => $vectors[$i] ?? [],
                'embedded_at' => Carbon::now(),
            ]);
        }

        return $missing->count();
    }

    /**
     * 套用一批草稿編輯(前端與 MCP 共用)。index 指「套用當下」串列中的位置,依序套用。
     * 內容或 context 有變的塊會清掉向量快取(commit 時重 embed);未動的塊保留快取。
     *
     * 支援的 op:
     *  - set_content {index, content}
     *  - set_context {index, context}
     *  - split       {index, at}        在第 at 個字元處把該塊切成兩塊
     *  - merge       {index}            與下一塊合併
     *  - delete      {index}
     *
     * @param  array<int, array<string, mixed>>  $ops
     * @return Document 重新整理後的文件(塊已重排 index)
     */
    public function applyEdits(Document $document, array $ops): Document
    {
        // 載入成可變串列:每筆 [content, context, embedding, embedded_at]
        $list = $document->chunks()->get()->map(fn (Chunk $c) => [
            'content' => $c->content,
            'context' => $c->context,
            'embedding' => $c->embedding,
            'embedded_at' => $c->embedded_at,
        ])->values()->all();

        foreach ($ops as $op) {
            $i = (int) ($op['index'] ?? -1);
            $type = (string) ($op['op'] ?? '');

            switch ($type) {
                case 'set_content':
                    $this->assertIndex($list, $i);
                    $list[$i]['content'] = (string) ($op['content'] ?? '');
                    $list[$i] = $this->dirtyEntry($list[$i]);
                    break;

                case 'set_context':
                    $this->assertIndex($list, $i);
                    $list[$i]['context'] = ($op['context'] ?? null) === null ? null : (string) $op['context'];
                    $list[$i] = $this->dirtyEntry($list[$i]);
                    break;

                case 'split':
                    $this->assertIndex($list, $i);
                    $at = max(1, (int) ($op['at'] ?? 0));
                    $content = $list[$i]['content'];
                    $first = mb_substr($content, 0, $at);
                    $second = mb_substr($content, $at);
                    $context = $list[$i]['context'];
                    array_splice($list, $i, 1, [
                        $this->dirtyEntry(['content' => $first, 'context' => $context]),
                        $this->dirtyEntry(['content' => $second, 'context' => $context]),
                    ]);
                    break;

                case 'merge':
                    $this->assertIndex($list, $i);
                    if (! isset($list[$i + 1])) {
                        throw new AIServiceException("merge 失敗:第 {$i} 塊沒有下一塊。");
                    }
                    $merged = $this->dirtyEntry([
                        'content' => $list[$i]['content']."\n".$list[$i + 1]['content'],
                        'context' => $list[$i]['context'] ?? $list[$i + 1]['context'],
                    ]);
                    array_splice($list, $i, 2, [$merged]);
                    break;

                case 'delete':
                    $this->assertIndex($list, $i);
                    array_splice($list, $i, 1);
                    break;

                default:
                    throw new AIServiceException("未知編輯 op: {$type}");
            }
        }

        return DB::transaction(function () use ($document, $list) {
            $document->chunks()->delete();
            foreach ($list as $idx => $entry) {
                Chunk::create([
                    'document_id' => $document->id,
                    'chunk_index' => $idx,
                    'content' => $entry['content'],
                    'context' => $entry['context'] ?? null,
                    'content_hash' => hash('sha256', $entry['content']),
                    'embedding' => $entry['embedding'] ?? null,
                    'embedded_at' => $entry['embedded_at'] ?? null,
                    'status' => ChunkStatus::Draft,
                ]);
            }
            if ($document->status === DocumentStatus::Committed) {
                $document->update(['status' => DocumentStatus::Dirty]);
            }

            return $document->refresh();
        });
    }

    /**
     * 宣告式設定草稿的完整塊集:整份替換成 $chunks(順序即最終順序)。
     * 無 index、無漂移(供 MCP/LLM 端宣告式編輯);以 embeddable(content+context)雜湊比對
     * 保留未變塊的向量快取——content 或 context 任一變即重 embed(因 embedding 含 context)。
     * 空內容塊略過,全空則擲例外。
     *
     * @param  array<int, array{content?: string, context?: string|null}>  $chunks
     */
    public function setChunks(Document $document, array $chunks): Document
    {
        // 舊塊:embeddable 雜湊 → 向量快取(content+context 皆同才可重用)
        $cache = [];
        foreach ($document->chunks()->get() as $c) {
            $cache[hash('sha256', $c->embeddableText())] ??= [
                'embedding' => $c->embedding,
                'embedded_at' => $c->embedded_at,
            ];
        }

        // 正規化目標塊:略過空內容塊
        $entries = [];
        foreach ($chunks as $row) {
            $content = (string) ($row['content'] ?? '');
            if (trim($content) === '') {
                continue;
            }
            $entries[] = [
                'content' => $content,
                'context' => ($row['context'] ?? null) === null ? null : (string) $row['context'],
            ];
        }
        if ($entries === []) {
            throw new AIServiceException('沒有可用的塊(內容皆為空)。');
        }

        return DB::transaction(function () use ($document, $entries, $cache) {
            $document->chunks()->delete();
            foreach ($entries as $i => $entry) {
                // 用 Chunk::embeddableText() 本身算 key,確保與舊塊比對規則一致
                $cached = $cache[hash('sha256', (new Chunk($entry))->embeddableText())] ?? null;
                Chunk::create([
                    'document_id' => $document->id,
                    'chunk_index' => $i,
                    'content' => $entry['content'],
                    'context' => $entry['context'],
                    'content_hash' => hash('sha256', $entry['content']),
                    'embedding' => $cached['embedding'] ?? null,
                    'embedded_at' => $cached['embedded_at'] ?? null,
                    'status' => ChunkStatus::Draft,
                ]);
            }
            if ($document->status === DocumentStatus::Committed) {
                $document->update(['status' => DocumentStatus::Dirty]);
            }

            return $document->refresh();
        });
    }

    /**
     * 標記一筆串列項為「已變」:清掉向量快取(commit 時重 embed)。
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function dirtyEntry(array $entry): array
    {
        $entry['embedding'] = null;
        $entry['embedded_at'] = null;

        return $entry;
    }

    /**
     * @param  array<int, mixed>  $list
     */
    private function assertIndex(array $list, int $i): void
    {
        if ($i < 0 || ! isset($list[$i])) {
            throw new AIServiceException("塊索引超出範圍: {$i}");
        }
    }

    // ── 檢索 ────────────────────────────────────────────────────────────

    /**
     * 對已 commit 的知識庫做語意檢索(走 vecgen)。
     *
     * @param  array<string, string>  $where
     * @return array<int, array{id: string, content: string, similarity: float, metadata: array<string, string>}>
     */
    public function query(KnowledgeBase $kb, string $queryText, int $topK = 5, array $where = []): array
    {
        $vector = $this->embedder->embed($queryText, ['task_type' => 'RETRIEVAL_QUERY']);

        $res = $this->vecgen('query', $kb->collectionName(), array_filter([
            'embedding' => $vector,
            'top_k' => $topK,
            'where' => $where,
        ]));

        return $res['results'] ?? [];
    }

    /**
     * 最小 Q&A:檢索 top-k → 組 grounding prompt → 打 LLM 生成回答。
     * 回 answer + sources(命中塊,含 file_name/chunk_index/similarity 供前端顯示出處)。
     *
     * 檢索無門檻(回滿 top_k),弱匹配也會進 context;答不出來時靠 system prompt 約束「資料沒有就說沒有」。
     *
     * @return array{answer: string, sources: array<int, array<string, mixed>>}
     */
    public function answer(KnowledgeBase $kb, string $question, int $topK = 5): array
    {
        $sources = $this->query($kb, $question, $topK);

        if ($sources === []) {
            return ['answer' => '知識庫中沒有相關內容,無法回答這個問題。', 'sources' => []];
        }

        $context = collect($sources)->map(function (array $hit, int $i) {
            $meta = $hit['metadata'] ?? [];
            $head = sprintf('[來源 %d｜%s #%s]', $i + 1, $meta['file_name'] ?? '?', $meta['chunk_index'] ?? '?');

            return $head."\n".($hit['content'] ?? '');
        })->implode("\n\n");

        $system = implode("\n\n", [
            '你是知識庫問答助理。只能依據以下「參考資料」回答使用者問題。',
            '若參考資料中沒有足以回答的資訊,直接說:「知識庫裡找不到這個問題的答案。」禁止推測或自行補充未提供的內容。',
            '回答時用繁體中文,並在句末以 [來源 N] 標註依據的來源編號。',
            '--- 參考資料 ---',
            $context,
            '--- 參考資料結束 ---',
        ]);

        $answer = $this->llm->for('chat')->generate($system, [['role' => 'user', 'text' => $question]]);

        return ['answer' => $answer, 'sources' => $sources];
    }

    /**
     * 草稿測試查詢:embed query → PHP 算 cosine 對草稿向量(不走 vecgen、不污染正式庫)。
     * 會順手 embed 缺向量的塊(快取,commit 時可重用)。
     *
     * @return array<int, array{chunk_index: int, content: string, similarity: float}>
     */
    public function testQueryDraft(Document $document, string $queryText, int $topK = 5): array
    {
        $this->ensureEmbeddings($document);
        $qv = $this->embedder->embed($queryText, ['task_type' => 'RETRIEVAL_QUERY']);

        $scored = $document->chunks()->get()->map(fn (Chunk $c) => [
            'chunk_index' => $c->chunk_index,
            'content' => $c->content,
            'similarity' => $this->cosine($qv, $c->embedding ?? []),
        ])->sortByDesc('similarity')->take($topK)->values()->all();

        return $scored;
    }

    /**
     * 草稿內近似重複:兩兩 cosine 超過門檻的塊配對(重用快取向量)。
     *
     * @return array<int, array{a: int, b: int, similarity: float}>
     */
    public function nearDuplicates(Document $document, ?float $threshold = null): array
    {
        $threshold ??= (float) config('rag.dedup.threshold', 0.95);
        $this->ensureEmbeddings($document);

        $chunks = $document->chunks()->get()->values();
        $pairs = [];
        for ($i = 0; $i < $chunks->count(); $i++) {
            for ($j = $i + 1; $j < $chunks->count(); $j++) {
                $sim = $this->cosine($chunks[$i]->embedding ?? [], $chunks[$j]->embedding ?? []);
                if ($sim >= $threshold) {
                    $pairs[] = [
                        'a' => $chunks[$i]->chunk_index,
                        'b' => $chunks[$j]->chunk_index,
                        'similarity' => round($sim, 4),
                    ];
                }
            }
        }

        return $pairs;
    }

    // ── 維運 ────────────────────────────────────────────────────────────

    /**
     * 知識庫統計(向量庫端塊數 + 全部 collection)。
     *
     * @return array{collection: string, count: int, collections: array<string, int>}
     */
    public function stats(KnowledgeBase $kb): array
    {
        return $this->vecgen('stats', $kb->collectionName());
    }

    /**
     * 刪知識庫的向量 collection（刪庫時連向量一起清）。best-effort:
     * 從未 commit 過的庫沒有 collection,reset 報錯可忽略(DB 才是真相)。
     */
    public function dropCollection(KnowledgeBase $kb): void
    {
        try {
            $this->vecgen('reset', $kb->collectionName());
        } catch (\Throwable) {
            // collection 不存在等情況忽略
        }
    }

    // ── 內部 ────────────────────────────────────────────────────────────

    /**
     * 餘弦相似度。維度不符或零向量回 0。
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $n = count($a);
        if ($n === 0 || $n !== count($b)) {
            return 0.0;
        }
        $dot = $na = $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na <= 0 || $nb <= 0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }

    /**
     * exec vecgen 一次:JSON 走 stdin、JSON 回 stdout,做完即退。
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function vecgen(string $command, string $collection, array $input = []): array
    {
        $bin = (string) config('rag.vecgen.bin');
        $db = (string) config('rag.vecgen.db');

        $result = Process::timeout(180)
            ->input($input === [] ? '{}' : json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
            ->run([$bin, $command, '--db', $db, '--collection', $collection]);

        $decoded = json_decode((string) $result->output(), true);

        if (! $result->successful()) {
            $msg = is_array($decoded) ? ($decoded['error'] ?? $result->errorOutput()) : $result->errorOutput();
            throw new AIServiceException("vecgen {$command} 失敗: ".$msg);
        }

        return is_array($decoded) ? $decoded : [];
    }
}

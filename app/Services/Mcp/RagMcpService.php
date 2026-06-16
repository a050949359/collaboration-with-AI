<?php

namespace App\Services\Mcp;

use App\Models\Rag\Document;
use App\Models\Rag\KnowledgeBase;
use App\Services\Rag\LockService;
use App\Services\Rag\RagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * RAG MCP 工具層:讓 Claude(Desktop/Code)互動式管理知識庫——
 * 列檔/建庫 → preview 切塊 → 上鎖 → 編輯 → commit → query。
 *
 * 權限兩層:身分由 MCP key(Auth::id())決定、KB/Document 一律過濾 owner;
 * 編輯/commit 另需 lock_token(LockService 驗 token + 持有者一致)。
 */
class RagMcpService implements McpToolServiceInterface
{
    private const TOOLS = [
        'rag_list_kbs', 'rag_create_kb', 'rag_delete_kb', 'rag_list_drive_files', 'rag_preview_chunks',
        'rag_lock', 'rag_unlock', 'rag_resume', 'rag_get_draft', 'rag_edit_chunks', 'rag_commit', 'rag_query',
    ];

    public function __construct(
        private RagService $rag,
        private LockService $locks,
    ) {}

    public function canHandle(string $name): bool
    {
        return \in_array($name, self::TOOLS);
    }

    public function call(string $name, array $args, mixed $id): JsonResponse
    {
        try {
            return match ($name) {
                'rag_list_kbs' => $this->listKbs($id),
                'rag_create_kb' => $this->createKb($id, $args),
                'rag_delete_kb' => $this->deleteKb($id, $args),
                'rag_list_drive_files' => $this->listDriveFiles($id, $args),
                'rag_preview_chunks' => $this->previewChunks($id, $args),
                'rag_lock' => $this->lock($id, $args),
                'rag_unlock' => $this->unlock($id, $args),
                'rag_resume' => $this->resume($id, $args),
                'rag_get_draft' => $this->getDraft($id, $args),
                'rag_edit_chunks' => $this->editChunks($id, $args),
                'rag_commit' => $this->commit($id, $args),
                'rag_query' => $this->query($id, $args),
                default => $this->text($id, "Unknown tool: $name", true),
            };
        } catch (Throwable $e) {
            return $this->text($id, $e->getMessage(), true);
        }
    }

    // ── 工具實作 ────────────────────────────────────────────────────────

    private function listKbs(mixed $id): JsonResponse
    {
        $kbs = KnowledgeBase::where('user_id', Auth::id())
            ->withCount('documents')
            ->get()
            ->map(fn (KnowledgeBase $kb) => [
                'id' => $kb->id,
                'name' => $kb->name,
                'collection' => $kb->collectionName(),
                'documents' => $kb->documents_count,
            ]);

        return $this->json($id, $kbs);
    }

    private function createKb(mixed $id, array $args): JsonResponse
    {
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            return $this->text($id, 'name 必填', true);
        }

        $kb = KnowledgeBase::create([
            'user_id' => Auth::id(),
            'name' => $name,
            'embedding_model' => (string) config('services.gemini.embedding_model'),
            'dimensions' => (int) config('services.gemini.embedding_dimensions'),
        ]);

        return $this->json($id, ['id' => $kb->id, 'collection' => $kb->collectionName()]);
    }

    private function deleteKb(mixed $id, array $args): JsonResponse
    {
        $kb = $this->ownedKb($args['kb_id'] ?? 0);
        $this->rag->dropCollection($kb);
        $kb->delete();

        return $this->json($id, ['ok' => true]);
    }

    private function listDriveFiles(mixed $id, array $args): JsonResponse
    {
        $kb = $this->ownedKb($args['kb_id'] ?? 0);

        return $this->json($id, $this->rag->listDriveFiles($kb));
    }

    private function previewChunks(mixed $id, array $args): JsonResponse
    {
        $kb = $this->ownedKb($args['kb_id'] ?? 0);
        $r = $this->rag->proposeDraftByFileId($kb, (string) ($args['drive_file_id'] ?? ''), (bool) ($args['force'] ?? false));
        $doc = $r['document'];

        return $this->json($id, [
            'document_id' => $doc->id,
            'chunks' => $r['chunks'],
            'diff' => $r['diff'],
            'loaded' => $r['loaded'],
            'preview' => $this->chunkList($doc),
        ]);
    }

    private function lock(mixed $id, array $args): JsonResponse
    {
        $doc = $this->ownedDoc($args['document_id'] ?? 0);
        $lock = $this->locks->acquire($doc, (int) Auth::id());

        return $this->json($id, ['lock_token' => $lock->lock_token, 'expires_at' => $lock->expires_at->toIso8601String()]);
    }

    private function unlock(mixed $id, array $args): JsonResponse
    {
        $doc = $this->ownedDoc($args['document_id'] ?? 0);
        $this->locks->release($doc, (string) ($args['lock_token'] ?? ''), (int) Auth::id());

        return $this->json($id, ['ok' => true]);
    }

    private function resume(mixed $id, array $args): JsonResponse
    {
        $lock = $this->locks->resolveByToken((string) ($args['lock_token'] ?? ''), (int) Auth::id());
        $doc = $lock->document;

        return $this->json($id, [
            'document_id' => $doc->id,
            'status' => $doc->status->value,
            'chunks' => $this->chunkList($doc),
        ]);
    }

    private function getDraft(mixed $id, array $args): JsonResponse
    {
        $doc = $this->ownedDoc($args['document_id'] ?? 0);

        return $this->json($id, ['document_id' => $doc->id, 'status' => $doc->status->value, 'chunks' => $this->chunkList($doc)]);
    }

    private function editChunks(mixed $id, array $args): JsonResponse
    {
        $doc = $this->ownedDoc($args['document_id'] ?? 0);
        $this->locks->verify($doc, (string) ($args['lock_token'] ?? ''), (int) Auth::id());

        $doc = $this->rag->applyEdits($doc, $args['ops'] ?? []);

        return $this->json($id, ['document_id' => $doc->id, 'chunks' => $this->chunkList($doc)]);
    }

    private function commit(mixed $id, array $args): JsonResponse
    {
        $doc = $this->ownedDoc($args['document_id'] ?? 0);
        $this->locks->verify($doc, (string) ($args['lock_token'] ?? ''), (int) Auth::id());

        return $this->json($id, $this->rag->commit($doc));
    }

    private function query(mixed $id, array $args): JsonResponse
    {
        $kb = $this->ownedKb($args['kb_id'] ?? 0);
        $hits = $this->rag->query($kb, (string) ($args['query'] ?? ''), (int) ($args['top_k'] ?? 5));

        return $this->json($id, $hits);
    }

    // ── 共用 ────────────────────────────────────────────────────────────

    private function ownedKb(mixed $kbId): KnowledgeBase
    {
        return KnowledgeBase::where('user_id', Auth::id())->findOrFail((int) $kbId);
    }

    private function ownedDoc(mixed $docId): Document
    {
        return Document::whereHas('knowledgeBase', fn ($q) => $q->where('user_id', Auth::id()))
            ->findOrFail((int) $docId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function chunkList(Document $document): array
    {
        return $document->chunks()->get()->map(fn ($c) => [
            'index' => $c->chunk_index,
            'content' => $c->content,
            'context' => $c->context,
            'chars' => mb_strlen($c->content),
            'embedded' => $c->embedding !== null,
        ])->all();
    }

    public function toolSchemas(): array
    {
        $docId = ['type' => 'integer', 'description' => 'rag_documents.id'];
        $lockToken = ['type' => 'string', 'description' => '由 rag_lock 取得的編輯 token'];

        return [
            ['name' => 'rag_list_kbs', 'description' => '列出我的知識庫(含文件數)。', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass]],
            ['name' => 'rag_create_kb', 'description' => '建立知識庫。', 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']]],
            ['name' => 'rag_delete_kb', 'description' => '刪除知識庫（連同文件/草稿/向量 collection 一併清除，不可復原）。', 'inputSchema' => ['type' => 'object', 'properties' => ['kb_id' => ['type' => 'integer']], 'required' => ['kb_id']]],
            ['name' => 'rag_list_drive_files', 'description' => '列全域 Drive 檔並標出相對此庫狀態(new/in_kb/changed)。', 'inputSchema' => ['type' => 'object', 'properties' => ['kb_id' => ['type' => 'integer']], 'required' => ['kb_id']]],
            ['name' => 'rag_preview_chunks', 'description' => '讀一個 Drive 檔、遞迴切塊並建/更新草稿(回提議塊與 diff);不會落庫。已有草稿預設直接載入(loaded=true,不覆蓋);force=true 才重新從 Drive 抽取重切。', 'inputSchema' => ['type' => 'object', 'properties' => ['kb_id' => ['type' => 'integer'], 'drive_file_id' => ['type' => 'string'], 'force' => ['type' => 'boolean', 'description' => '強制重切(會覆蓋現有草稿編輯)']], 'required' => ['kb_id', 'drive_file_id']]],
            ['name' => 'rag_lock', 'description' => '取得文件編輯鎖,回 lock_token(編輯/commit 必帶)。', 'inputSchema' => ['type' => 'object', 'properties' => ['document_id' => $docId], 'required' => ['document_id']]],
            ['name' => 'rag_unlock', 'description' => '釋放文件編輯鎖。', 'inputSchema' => ['type' => 'object', 'properties' => ['document_id' => $docId, 'lock_token' => $lockToken], 'required' => ['document_id', 'lock_token']]],
            ['name' => 'rag_resume', 'description' => '用 lock_token 接手草稿(交接用:前端上鎖拿 token、貼來這裡)。回 document_id + 當前草稿塊,之後即可帶 document_id+lock_token 編輯/commit。', 'inputSchema' => ['type' => 'object', 'properties' => ['lock_token' => $lockToken], 'required' => ['lock_token']]],
            ['name' => 'rag_get_draft', 'description' => '取得文件目前草稿塊。', 'inputSchema' => ['type' => 'object', 'properties' => ['document_id' => $docId], 'required' => ['document_id']]],
            ['name' => 'rag_edit_chunks', 'description' => '套用草稿編輯。ops 為陣列,每項 op∈{set_content,set_context,split,merge,delete},index 指當下塊位置;split 需 at(字元位移)。', 'inputSchema' => ['type' => 'object', 'properties' => ['document_id' => $docId, 'lock_token' => $lockToken, 'ops' => ['type' => 'array', 'items' => ['type' => 'object']]], 'required' => ['document_id', 'lock_token', 'ops']]],
            ['name' => 'rag_commit', 'description' => '把草稿落庫(embed 變更塊 + upsert 進向量庫)。', 'inputSchema' => ['type' => 'object', 'properties' => ['document_id' => $docId, 'lock_token' => $lockToken], 'required' => ['document_id', 'lock_token']]],
            ['name' => 'rag_query', 'description' => '對已 commit 的知識庫做語意檢索。', 'inputSchema' => ['type' => 'object', 'properties' => ['kb_id' => ['type' => 'integer'], 'query' => ['type' => 'string'], 'top_k' => ['type' => 'integer']], 'required' => ['kb_id', 'query']]],
        ];
    }

    private function json(mixed $id, mixed $data): JsonResponse
    {
        return $this->text($id, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function text(mixed $id, string $text, bool $isError = false): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [['type' => 'text', 'text' => $text]],
                'isError' => $isError,
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Rag;

use App\Http\Controllers\Controller;
use App\Models\Rag\Document;
use App\Models\Rag\KnowledgeBase;
use App\Services\Rag\LockService;
use App\Services\Rag\RagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DocumentController extends Controller
{
    public function __construct(
        private RagService $rag,
        private LockService $locks,
    ) {}

    /** 從 Drive 檔產生/更新草稿(preview)。 */
    public function store(Request $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        abort_unless($knowledgeBase->user_id === Auth::id(), 403);
        $data = $request->validate(['drive_file_id' => 'required|string']);

        $r = $this->rag->proposeDraftByFileId($knowledgeBase, $data['drive_file_id']);

        return response()->json([
            'document_id' => $r['document']->id,
            'chunks' => $r['chunks'],
            'diff' => $r['diff'],
        ], 201);
    }

    /** 取草稿塊 + 鎖狀態。 */
    public function chunks(Document $document): JsonResponse
    {
        $this->authorizeDoc($document);

        return response()->json([
            'document_id' => $document->id,
            'status' => $document->status->value,
            'lock' => $this->locks->status($document),
            'chunks' => $this->chunkList($document),
        ]);
    }

    public function lock(Document $document): JsonResponse
    {
        $this->authorizeDoc($document);
        $lock = $this->locks->acquire($document, (int) Auth::id());

        return response()->json([
            'lock_token' => $lock->lock_token,
            'expires_at' => $lock->expires_at->toIso8601String(),
        ]);
    }

    public function unlock(Request $request, Document $document): JsonResponse
    {
        $this->authorizeDoc($document);
        $data = $request->validate(['lock_token' => 'required|string']);
        $this->locks->release($document, $data['lock_token'], (int) Auth::id());

        return response()->json(['ok' => true]);
    }

    /** 套用一批草稿編輯(需 lock_token)。 */
    public function edit(Request $request, Document $document): JsonResponse
    {
        $this->authorizeDoc($document);
        $data = $request->validate([
            'lock_token' => 'required|string',
            'ops' => 'required|array',
        ]);
        $this->locks->verify($document, $data['lock_token'], (int) Auth::id());

        $document = $this->rag->applyEdits($document, $data['ops']);

        return response()->json([
            'document_id' => $document->id,
            'chunks' => $this->chunkList($document),
            'near_duplicates' => $this->rag->nearDuplicates($document),
        ]);
    }

    /** 草稿測試查詢(PHP cosine,不落庫)。 */
    public function testQuery(Request $request, Document $document): JsonResponse
    {
        $this->authorizeDoc($document);
        $data = $request->validate([
            'query' => 'required|string',
            'top_k' => 'nullable|integer|min:1|max:50',
        ]);

        return response()->json([
            'data' => $this->rag->testQueryDraft($document, $data['query'], $data['top_k'] ?? 5),
        ]);
    }

    /** 落庫(需 lock_token)。 */
    public function commit(Request $request, Document $document): JsonResponse
    {
        $this->authorizeDoc($document);
        $data = $request->validate(['lock_token' => 'required|string']);
        $this->locks->verify($document, $data['lock_token'], (int) Auth::id());

        return response()->json($this->rag->commit($document));
    }

    private function authorizeDoc(Document $document): void
    {
        abort_unless($document->knowledgeBase->user_id === Auth::id(), 403);
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
}

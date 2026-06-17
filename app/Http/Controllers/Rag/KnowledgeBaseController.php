<?php

namespace App\Http\Controllers\Rag;

use App\Http\Controllers\Controller;
use App\Models\Rag\Document;
use App\Models\Rag\KnowledgeBase;
use App\Services\Rag\DriveReader;
use App\Services\Rag\RagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class KnowledgeBaseController extends Controller
{
    public function __construct(private RagService $rag) {}

    /** 列全域 Drive 檔(無 KB 脈絡,供 step1 先選檔)。 */
    public function driveIndex(DriveReader $drive): JsonResponse
    {
        return response()->json(['data' => $drive->list()]);
    }

    public function index(Request $request): JsonResponse
    {
        // 若帶 drive_file_id，標出該檔在各庫的狀態（new/draft/committed/dirty）
        $fileId = $request->query('drive_file_id');
        $statusByKb = $fileId
            ? Document::whereHas('knowledgeBase', fn ($q) => $q->where('user_id', Auth::id()))
                ->where('drive_file_id', $fileId)
                ->pluck('status', 'knowledge_base_id')
                ->all()
            : [];

        $kbs = KnowledgeBase::where('user_id', Auth::id())
            ->withCount([
                // 進過向量庫＝committed_at 有值（committed/dirty 都算）；未落庫過＝null
                'documents as committed_count' => fn ($q) => $q->whereNotNull('committed_at'),
                'documents as draft_count' => fn ($q) => $q->whereNull('committed_at'),
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (KnowledgeBase $kb) => [
                'id' => $kb->id,
                'name' => $kb->name,
                'collection' => $kb->collectionName(),
                'committed_count' => $kb->committed_count,
                'draft_count' => $kb->draft_count,
                'file_status' => $fileId ? ($statusByKb[$kb->id] ?? 'new') : null,
            ]);

        return response()->json(['data' => $kbs]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255']);

        $kb = KnowledgeBase::create([
            'user_id' => Auth::id(),
            'name' => $data['name'],
            'embedding_model' => (string) config('services.gemini.embedding_model'),
            'dimensions' => (int) config('services.gemini.embedding_dimensions'),
        ]);

        return response()->json(['id' => $kb->id, 'collection' => $kb->collectionName()], 201);
    }

    /**
     * 總覽 dashboard：每個庫 → 文件 → 塊數 + 狀態 + 向量庫實際塊數（揪不同步）。
     * 資料全在 DB，向量數一次 vecgen stats（collections 欄含全部 collection）。
     */
    public function dashboard(): JsonResponse
    {
        $kbs = KnowledgeBase::where('user_id', Auth::id())
            ->with(['documents' => fn ($q) => $q->withCount('chunks')->orderBy('name')])
            ->orderByDesc('created_at')
            ->get();

        // 一次拿到所有 collection 的向量數
        $vectorCounts = $kbs->isNotEmpty() ? ($this->rag->stats($kbs->first())['collections'] ?? []) : [];

        $data = $kbs->map(fn (KnowledgeBase $kb) => [
            'id' => $kb->id,
            'name' => $kb->name,
            'collection' => $kb->collectionName(),
            'embedding_model' => $kb->embedding_model,
            'dimensions' => $kb->dimensions,
            'vector_count' => $vectorCounts[$kb->collectionName()] ?? 0,
            'documents' => $kb->documents->map(fn (Document $d) => [
                'drive_file_id' => $d->drive_file_id,
                'name' => $d->name,
                'status' => $d->status->value,
                'chunk_count' => $d->chunks_count,
                'committed_at' => $d->committed_at?->toIso8601String(),
            ]),
        ]);

        return response()->json(['data' => $data]);
    }

    public function destroy(KnowledgeBase $knowledgeBase): JsonResponse
    {
        abort_unless($knowledgeBase->user_id === Auth::id(), 403);

        // 先清向量 collection，再刪 DB（documents/chunks/locks 隨 FK cascade）
        $this->rag->dropCollection($knowledgeBase);
        $knowledgeBase->delete();

        return response()->json(null, 204);
    }

    public function driveFiles(KnowledgeBase $knowledgeBase): JsonResponse
    {
        $this->authorizeKb($knowledgeBase);

        return response()->json(['data' => $this->rag->listDriveFiles($knowledgeBase)]);
    }

    public function query(Request $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        $this->authorizeKb($knowledgeBase);
        $data = $request->validate([
            'query' => 'required|string',
            'top_k' => 'nullable|integer|min:1|max:50',
        ]);

        $hits = $this->rag->query($knowledgeBase, $data['query'], $data['top_k'] ?? 5);

        return response()->json(['data' => $hits]);
    }

    /** 最小 Q&A：檢索 top-k → 組 prompt → LLM 生成回答（回 answer + sources）。 */
    public function ask(Request $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        $this->authorizeKb($knowledgeBase);
        $data = $request->validate([
            'query' => 'required|string',
            'top_k' => 'nullable|integer|min:1|max:50',
        ]);

        $result = $this->rag->answer($knowledgeBase, $data['query'], $data['top_k'] ?? 5);

        return response()->json(['data' => $result]);
    }

    private function authorizeKb(KnowledgeBase $kb): void
    {
        abort_unless($kb->user_id === Auth::id(), 403);
    }
}

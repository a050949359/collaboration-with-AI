<?php

namespace App\Http\Controllers\Rag;

use App\Http\Controllers\Controller;
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

    public function index(): JsonResponse
    {
        $kbs = KnowledgeBase::where('user_id', Auth::id())
            ->withCount('documents')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (KnowledgeBase $kb) => [
                'id' => $kb->id,
                'name' => $kb->name,
                'collection' => $kb->collectionName(),
                'documents_count' => $kb->documents_count,
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

    private function authorizeKb(KnowledgeBase $kb): void
    {
        abort_unless($kb->user_id === Auth::id(), 403);
    }
}

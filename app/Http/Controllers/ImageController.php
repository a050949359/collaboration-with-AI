<?php

namespace App\Http\Controllers;

use App\Enums\ImageVisibility;
use App\Http\Requests\StoreImageRequest;
use App\Services\Image\ImageIngestService;
use App\Services\Image\ImageRejectedException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImageController extends Controller
{
    public function __construct(private readonly ImageIngestService $images) {}

    /**
     * 【admin】接收 file(拖曳上傳)或 url(伺服器下載),轉 webp 存對應 disk。
     * visibility 可由 request 指定(預設 private):public 回 /storage 直連、private 回鑑權出圖。
     */
    public function store(StoreImageRequest $request): JsonResponse
    {
        $visibility = ImageVisibility::from(
            (string) $request->input('visibility', config('images.default_visibility')),
        );

        return $this->ingest($request, $visibility);
    }

    /**
     * 【任一登入者】上傳但**強制 public** —— 不可建立 private(忽略 request 的 visibility)。
     * 給一般使用者貢獻公開素材用,private/NSFW 仍只有 admin 能放。
     */
    public function storePublic(StoreImageRequest $request): JsonResponse
    {
        return $this->ingest($request, ImageVisibility::Public);
    }

    /**
     * 共用:跑 pipeline 並依 visibility 回 URL。
     */
    private function ingest(StoreImageRequest $request, ImageVisibility $visibility): JsonResponse
    {
        try {
            $id = $request->hasFile('file')
                ? $this->images->fromUpload($request->file('file'), $visibility)
                : $this->images->fromUrl((string) $request->input('url'), $visibility);
        } catch (ImageRejectedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $id,
            'visibility' => $visibility->value,
            'url' => $this->urlFor($id, $visibility),
        ], 201);
    }

    /**
     * 依 visibility 給出對外 URL:
     * - public:public disk 的 /storage 直連(無需鑑權)
     * - private:鑑權出圖路由
     */
    private function urlFor(string $id, ImageVisibility $visibility): string
    {
        if ($visibility === ImageVisibility::Public) {
            $path = trim((string) config('images.directory'), '/').'/'.$id.'.webp';

            // local/public driver 的 url() 在具體 FilesystemAdapter 上,
            // Storage::disk() 的回傳型別宣告是 Filesystem interface(無 url()),故先 narrow。
            /** @var FilesystemAdapter $disk */
            $disk = Storage::disk((string) config('images.disks.public'));

            return $disk->url($path);
        }

        return route('images.show', ['id' => $id]);
    }

    /**
     * 鑑權出圖(僅 private):須登入(路由 auth:sanctum);id 已由路由 regex 限成 uuid 格式,
     * 不存在回 404,存在則串流 webp。
     */
    public function show(string $id): StreamedResponse
    {
        $path = trim((string) config('images.directory'), '/').'/'.$id.'.webp';

        // response() 在具體 FilesystemAdapter 上,先 narrow 型別(同 urlFor)。
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) config('images.disks.private'));

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $id.'.webp', [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}

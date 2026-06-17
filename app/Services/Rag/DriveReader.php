<?php

namespace App\Services\Rag;

use App\Services\AI\AIServiceException;
use App\Services\AI\GcpAccessTokenProvider;
use Illuminate\Support\Facades\Http;

/**
 * 讀取分享給專用 SA 的 Google Drive 資料夾，抽出可向量化的純文字（T1）。
 *
 * T1 範圍：Google 原生文件（Docs/Sheets/Slides，走 files.export）+ 純文字類
 * 上傳檔（text/markdown/csv/json…，走 alt=media）。其餘（PDF/Office/圖片）暫略過。
 */
class DriveReader
{
    private const DRIVE_API = 'https://www.googleapis.com/drive/v3';

    private GcpAccessTokenProvider $token;

    /**
     * Google 原生檔 → export 的目標 MIME（純文字向）。
     *
     * @var array<string, string>
     */
    private const NATIVE_EXPORT = [
        'application/vnd.google-apps.document' => 'text/plain',
        'application/vnd.google-apps.spreadsheet' => 'text/csv',
        'application/vnd.google-apps.presentation' => 'text/plain',
    ];

    public function __construct()
    {
        $this->token = new GcpAccessTokenProvider(
            config('rag.drive.credentials_path'),
            (string) config('rag.drive.scope', 'https://www.googleapis.com/auth/drive.readonly'),
        );
    }

    /**
     * 列出資料夾內「可萃取(T1)」的檔案後設資料,不抽文字(供選檔/狀態列表用,較省)。
     *
     * @return array<int, array{id: string, name: string, mime_type: string, modified_time: string}>
     */
    public function list(?string $folderId = null): array
    {
        $folderId = $folderId ?: (string) config('rag.drive.folder_id');

        if ($folderId === '') {
            throw new AIServiceException('RAG_DRIVE_FOLDER_ID 未設定。');
        }

        return array_values(array_filter(
            $this->listFiles($folderId),
            fn ($file) => $this->isSupported($file['mime_type']),
        ));
    }

    /**
     * 抽單一檔案的純文字(原生檔走 export、其餘走 alt=media)。
     */
    public function extract(string $fileId, string $mimeType): string
    {
        return $this->extractText($fileId, $mimeType);
    }

    /**
     * 列出資料夾內的 T1 檔並抽出文字(便利方法:list + extract)。
     *
     * @return array<int, array{id: string, name: string, mime_type: string, modified_time: string, text: string}>
     */
    public function listAndExtract(?string $folderId = null): array
    {
        $out = [];
        foreach ($this->list($folderId) as $file) {
            $text = $this->extractText($file['id'], $file['mime_type']);
            if (trim($text) === '') {
                continue;
            }
            $out[] = [...$file, 'text' => $text];
        }

        return $out;
    }

    /**
     * 列出資料夾直接子檔（處理分頁）。
     *
     * @return array<int, array{id: string, name: string, mime_type: string, modified_time: string}>
     */
    private function listFiles(string $folderId): array
    {
        $files = [];
        $pageToken = null;

        do {
            $res = Http::withToken($this->token->getToken())
                ->acceptJson()
                ->timeout(30)
                ->get(self::DRIVE_API.'/files', array_filter([
                    'q' => "'{$folderId}' in parents and trashed = false",
                    'fields' => 'nextPageToken, files(id, name, mimeType, modifiedTime)',
                    'pageSize' => 1000,
                    'supportsAllDrives' => 'true',
                    'includeItemsFromAllDrives' => 'true',
                    'pageToken' => $pageToken,
                ]));

            if (! $res->ok()) {
                throw new AIServiceException('Drive files.list 失敗: '.$res->status().' - '.$res->body());
            }

            foreach ($res->json('files', []) as $f) {
                $files[] = [
                    'id' => (string) ($f['id'] ?? ''),
                    'name' => (string) ($f['name'] ?? ''),
                    'mime_type' => (string) ($f['mimeType'] ?? ''),
                    'modified_time' => (string) ($f['modifiedTime'] ?? ''),
                ];
            }

            $pageToken = $res->json('nextPageToken');
        } while ($pageToken);

        return $files;
    }

    private function isSupported(string $mime): bool
    {
        return isset(self::NATIVE_EXPORT[$mime])
            || str_starts_with($mime, 'text/')
            || $mime === 'application/json';
    }

    /**
     * 原生檔走 export、其餘走 alt=media 下載原始位元組（純文字）。
     */
    private function extractText(string $fileId, string $mime): string
    {
        if (isset(self::NATIVE_EXPORT[$mime])) {
            $res = Http::withToken($this->token->getToken())
                ->timeout(60)
                ->get(self::DRIVE_API."/files/{$fileId}/export", [
                    'mimeType' => self::NATIVE_EXPORT[$mime],
                ]);
        } else {
            $res = Http::withToken($this->token->getToken())
                ->timeout(60)
                ->get(self::DRIVE_API."/files/{$fileId}", [
                    'alt' => 'media',
                    'supportsAllDrives' => 'true',
                ]);
        }

        if (! $res->ok()) {
            throw new AIServiceException("Drive 取檔失敗 ({$fileId}): ".$res->status().' - '.$res->body());
        }

        return $res->body();
    }
}

<?php

namespace App\Http\Requests;

use App\Enums\ImageVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImageRequest extends FormRequest
{
    /**
     * 鑑權由路由 auth:sanctum 負責。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * file 與 url 二擇一(互斥),交給 ImageIngestService 做內容層安全驗證。
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        $maxKb = (int) ceil(((int) config('images.max_bytes')) / 1024);

        return [
            'file' => ['required_without:url', 'prohibits:url', 'file', 'max:'.$maxKb],
            'url' => ['required_without:file', 'prohibits:file', 'string', 'url', 'max:2048'],
            'visibility' => ['nullable', Rule::enum(ImageVisibility::class)],
        ];
    }
}

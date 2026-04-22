<?php

namespace App\Http\Requests\Article;

use App\Rules\NoMaliciousPattern;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:150', new NoMaliciousPattern()],
            'prompt' => ['nullable', 'string', 'max:2000', new NoMaliciousPattern()],
        ];
    }
}

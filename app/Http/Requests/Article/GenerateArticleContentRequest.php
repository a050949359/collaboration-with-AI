<?php

namespace App\Http\Requests\Article;

use App\Enums\ArticleLanguage;
use App\Enums\ArticleStyle;
use App\Enums\ArticleTopic;
use App\Rules\NoMaliciousPattern;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class GenerateArticleContentRequest extends FormRequest
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
            'topic'    => ['required', new Enum(ArticleTopic::class)],
            'language' => ['required', new Enum(ArticleLanguage::class)],
            'style'    => ['required', new Enum(ArticleStyle::class)],
            'prompt'   => ['nullable', 'string', 'max:300', new NoMaliciousPattern()],
        ];
    }
}

<?php

namespace App\Http\Requests\Article;

use App\Rules\NoMaliciousPattern;
use Illuminate\Foundation\Http\FormRequest;

class UpdateArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'   => ['sometimes', 'nullable', 'string', 'max:255', new NoMaliciousPattern()],
            'content' => ['sometimes', 'nullable', 'string'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:500', new NoMaliciousPattern()],
            'tags'    => ['sometimes', 'nullable', 'array'],
            'tags.*'  => ['string', 'max:50', new NoMaliciousPattern()],
        ];
    }

    protected function prepareForValidation(): void
    {
        $title = $this->input('title');
        $summary = $this->input('summary');
        $content = $this->input('content');
        $tags = $this->input('tags');

        $this->merge([
            'title' => is_string($title) ? trim(strip_tags($title)) : $title,
            'summary' => is_string($summary) ? trim(strip_tags($summary)) : $summary,
            // Content keeps plain text/markdown and strips HTML tags to reduce XSS risk.
            'content' => is_string($content) ? trim(strip_tags($content)) : $content,
            'tags' => is_array($tags)
                ? array_values(array_filter(array_map(
                    static fn ($tag) => is_string($tag) ? trim(strip_tags($tag)) : $tag,
                    $tags,
                ), static fn ($tag) => is_string($tag) && $tag !== ''))
                : $tags,
        ]);
    }
}

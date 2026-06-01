<?php

namespace App\Http\Requests\Story;

use App\Enums\StoryGenre;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'keywords' => ['required', 'string', 'max:200'],
            'genre'    => ['nullable', Rule::enum(StoryGenre::class)],
        ];
    }
}

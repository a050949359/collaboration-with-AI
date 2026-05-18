<?php

namespace App\Http\Requests\Story;

use Illuminate\Foundation\Http\FormRequest;

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
            'genre'    => ['nullable', 'string', 'in:fantasy,mystery,scifi,modern'],
        ];
    }
}

<?php

namespace App\Http\Requests\Story;

use Illuminate\Foundation\Http\FormRequest;

class RefineSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'setup'              => ['required', 'array'],
            'setup.world'        => ['required', 'string'],
            'setup.characters'   => ['required', 'array', 'min:2'],
            'setup.opening'      => ['required', 'string'],
            'setup.items'        => ['nullable', 'array'],
            'notes'              => ['nullable', 'string', 'max:500'],
        ];
    }
}

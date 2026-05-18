<?php

namespace App\Http\Requests\Story;

use Illuminate\Foundation\Http\FormRequest;

class PlayerTurnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}

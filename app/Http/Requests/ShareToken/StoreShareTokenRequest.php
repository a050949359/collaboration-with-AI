<?php

namespace App\Http\Requests\ShareToken;

use App\Enums\ShareTokenScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShareTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'scope'      => ['required', 'string', Rule::enum(ShareTokenScope::class)],
            'max_uses'   => ['nullable', 'integer', 'min:1'],
            'note'       => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}

<?php

namespace App\Http\Requests\ShareToken;

use App\Enums\ShareTokenScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckShareTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'scope' => ['required', 'string', Rule::enum(ShareTokenScope::class)],
        ];
    }
}

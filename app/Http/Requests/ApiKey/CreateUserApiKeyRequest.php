<?php

namespace App\Http\Requests\ApiKey;

use App\Enums\ApiKeyScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateUserApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name'      => ['sometimes', 'string', 'max:64'],
            'scopes'    => ['sometimes', 'nullable', 'array'],
            'scopes.*'  => [Rule::enum(ApiKeyScope::class)],
            'publicKey' => ['required', 'string', 'starts_with:-----BEGIN'],
        ];
    }
}

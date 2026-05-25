<?php

namespace App\Http\Requests\ApiKey;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
            'type'      => ['required', 'string', 'in:mcp'],
            'publicKey' => ['required', 'string', 'starts_with:-----BEGIN'],
        ];
    }
}

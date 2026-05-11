<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegistRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8) // 至少 8 個字
                ->letters()   // 必須包含字母
                ->mixedCase() // 必須包含大小寫
                ->numbers()   // 必須包含數字
                ->symbols()   // 必須包含特殊符號
            ],
            'password_confirmation' => ['required', 'same:password'],
            'terms' => ['required', 'accepted'],
            'cf_turnstile_response' => [app()->isLocal() ? 'nullable' : 'required', 'string'],
        ];
    }
}

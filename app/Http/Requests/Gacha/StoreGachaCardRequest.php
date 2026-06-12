<?php

namespace App\Http\Requests\Gacha;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGachaCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'   => 'required|string|max:50',
            'rarity' => ['required', Rule::in(['common', 'rare', 'epic', 'legendary'])],
            'weight' => 'required|integer|min:1|max:9999',
        ];
    }
}

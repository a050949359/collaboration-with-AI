<?php

namespace App\Http\Requests\Gacha;

use App\Enums\GachaRarity;
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
            'name' => 'required|string|max:50',
            'rarity' => ['required', Rule::enum(GachaRarity::class)],
            'weight' => 'required|integer|min:1|max:9999',
        ];
    }
}

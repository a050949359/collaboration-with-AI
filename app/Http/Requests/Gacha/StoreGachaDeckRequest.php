<?php

namespace App\Http\Requests\Gacha;

use Illuminate\Foundation\Http\FormRequest;

class StoreGachaDeckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'       => 'required|string|max:50',
            'card_ids'   => 'nullable|array|distinct',
            'card_ids.*' => 'integer|exists:gacha_cards,id',
        ];
    }
}

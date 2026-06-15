<?php

namespace App\Http\Requests\Gacha;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGachaDeckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'       => 'sometimes|string|max:50',
            'card_ids'   => 'nullable|array|distinct',
            'card_ids.*' => 'integer|exists:gacha_cards,id',
        ];
    }
}

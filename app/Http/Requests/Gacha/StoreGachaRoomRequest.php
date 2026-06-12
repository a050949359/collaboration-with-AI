<?php

namespace App\Http\Requests\Gacha;

use Illuminate\Foundation\Http\FormRequest;

class StoreGachaRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_name'   => 'nullable|string|max:50',
            'player_name' => 'nullable|string|max:30',
            'deck_id'     => 'nullable|integer|exists:gacha_decks,id',
        ];
    }
}

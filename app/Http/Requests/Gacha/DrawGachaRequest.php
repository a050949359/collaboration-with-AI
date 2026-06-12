<?php

namespace App\Http\Requests\Gacha;

use Illuminate\Foundation\Http\FormRequest;

class DrawGachaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'player_id'      => 'required|integer',
            'is_ten_pull'    => 'boolean',
            'can_draw'       => 'boolean',
            'draws_per_user' => 'integer|min:0',
        ];
    }
}

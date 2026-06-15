<?php

namespace App\Http\Requests\Gacha;

use Illuminate\Foundation\Http\FormRequest;

class JoinGachaRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:30',
        ];
    }
}

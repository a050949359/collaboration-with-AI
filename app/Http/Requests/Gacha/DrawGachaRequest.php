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
        // 機台狀態（can_draw / draws_per_user / is_ten_pull）一律由後端向
        // ws server 查詢 host 設定的 machine_state，不信任 client 傳入。
        return [
            'player_id' => 'required|integer',
        ];
    }
}

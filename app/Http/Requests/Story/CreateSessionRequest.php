<?php

namespace App\Http\Requests\Story;

use Illuminate\Foundation\Http\FormRequest;

class CreateSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title'                          => ['required', 'string', 'max:100'],
            'setting'                        => ['required', 'array'],
            'setting.world'                  => ['required', 'string'],
            'setting.opening'                => ['required', 'string'],
            'advance_interval_minutes'       => ['nullable', 'integer', 'min:10', 'max:1440'],
            'rounds_per_advance'             => ['nullable', 'integer', 'min:1', 'max:10'],
            'content_rating'                 => ['nullable', 'string', 'in:general,mature'],
            'characters'                     => ['required', 'array', 'min:2'],
            'characters.*.name'              => ['required', 'string', 'max:50'],
            'characters.*.persona'           => ['required', 'string', 'max:500'],
            'characters.*.type'              => ['nullable', 'string', 'in:llm,player,npc'],
            'characters.*.model_config'      => ['nullable', 'array'],
            'characters.*.is_narrator'       => ['nullable', 'boolean'],
            'items'                          => ['nullable', 'array'],
            'items.*.name'                   => ['required', 'string', 'max:100'],
            'items.*.description'            => ['required', 'string', 'max:300'],
            'items.*.holder'                 => ['nullable', 'string'],
        ];
    }
}

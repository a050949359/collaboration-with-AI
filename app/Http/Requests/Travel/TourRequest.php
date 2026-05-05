<?php

namespace App\Http\Requests\Travel;

use App\Enums\TourType;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TourRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        
        return [
            'code'           => ['sometimes', 'string', 'max:50'],
            'name'           => [$required, 'string', 'max:255'],
            'type'           => [$required, 'string', Rule::in(array_column(TourType::cases(), 'value'))],
            'duration'       => [$required, 'integer', 'min:1'],
            'departure_date' => [$required, 'date', 'before:return_date'],
            'return_date'    => [$required, 'date', 'after:departure_date'],
            'selling_price'  => [$required, 'numeric', 'min:0'],
            'target_profit'  => ['sometimes', 'nullable', 'numeric'],
            'min_pax'        => [$required, 'integer', 'min:1', 'lte:max_pax'],
            'max_pax'        => [$required, 'integer', 'min:1', 'gte:min_pax'],
            'tour_leader_id' => ['sometimes', 'nullable', 'exists:tour_leaders,id'],
            'remarks'        => ['sometimes', 'nullable', 'string'],
        ];
    }
}

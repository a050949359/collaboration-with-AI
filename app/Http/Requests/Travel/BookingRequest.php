<?php

namespace App\Http\Requests\Travel;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BookingRequest extends FormRequest
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
        return [
            'tour_id'             => ['required', 'integer', 'exists:tours,id'],
            'passenger_id'        => ['required', 'integer', 'exists:passengers,id'],
            'companions'          => ['sometimes', 'array'],
            'companions.*'        => ['integer', 'exists:passengers,id'],
            'number_of_travelers' => ['required', 'integer', 'min:1'],
            'discount_amount'     => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'final_amount'        => ['required', 'numeric', 'min:0'],
            'remarks'             => ['sometimes', 'nullable', 'string'],
        ];
    }
}

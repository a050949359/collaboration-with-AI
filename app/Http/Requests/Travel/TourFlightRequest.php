<?php

namespace App\Http\Requests\Travel;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TourFlightRequest extends FormRequest
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
            'flight_number'          => ['required', 'string', 'max:20'],
            'cabin_class'            => ['required', 'string', 'in:economy,premium_economy,business,first'],
            'origin_airport_id'      => ['required', 'integer', 'exists:airports,id'],
            'destination_airport_id' => ['required', 'integer', 'exists:airports,id', 'different:origin_airport_id'],
            'departure_time'         => ['required', 'date'],
            'arrival_time'           => ['required', 'date', 'after:departure_time'],
            'cost_price'             => ['nullable', 'numeric', 'min:0'],
            'remarks'                => ['nullable', 'string'],
        ];
    }
}

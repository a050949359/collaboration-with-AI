<?php

namespace App\Http\Requests\Travel;

use App\Enums\RoomType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TourHotelRequest extends FormRequest
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
            'hotel_name'          => ['required', 'string', 'max:255'],
            'check_in_date'       => ['required', 'date'],
            'check_out_date'      => ['required', 'date', 'after:check_in_date'],
            'room_type'           => ['required', Rule::enum(RoomType::class)],
            'number_of_rooms'     => ['required', 'integer', 'min:1'],
            'cost_price_per_night'=> ['required', 'numeric', 'min:0'],
            'remarks'             => ['nullable', 'string'],
        ];
    }
}

<?php

namespace App\Models\Travel;

use App\Enums\RoomType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'tour_id', 'hotel_name', 'room_type',
    'check_in_date', 'check_out_date', 'nights',
    'number_of_rooms', 'cost_price_per_night', 'total_cost_price', 'remarks',
])]
class TourHotel extends Model
{
    use HasFactory;
    protected $casts = [
        'room_type'      => RoomType::class,
        'check_in_date'  => 'date',
        'check_out_date' => 'date',
    ];

    public function tour()
    {
        return $this->belongsTo(Tour::class);
    }
}

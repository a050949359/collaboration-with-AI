<?php

namespace App\Models\Travel;

// use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name', 'email', 'phone', 'license_number'])]
class TourLeader extends Model
{
    use HasFactory;
    
    public function user()
    {
        // return $this->belongsTo(User::class);
    }

    public function tours()
    {
        return $this->hasMany(Tour::class);
    }
}
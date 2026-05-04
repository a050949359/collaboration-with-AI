<?php

use App\Enums\RoomType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tour_hotels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_id')->constrained();
            $table->string('hotel_name');
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->string('room_type')->default(RoomType::Single->value);
            $table->integer('number_of_rooms')->default(1);
            $table->integer('nights');
            $table->decimal('cost_price_per_night', 10, 2)->default(0);
            $table->decimal('total_cost_price', 10, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tour_hotels');
    }
};

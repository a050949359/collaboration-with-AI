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
        Schema::create('booking_hotels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->comment('訂單 ID');
            $table->string('hotel_name')->comment('飯店名稱');
            $table->date('check_in_date')->comment('入住日期');
            $table->date('check_out_date')->comment('退房日期');
            $table->string('room_type')->default(RoomType::Single->value)->comment('房型');
            $table->integer('number_of_rooms')->comment('房間數量');
            $table->integer('nights')->comment('晚數');
            $table->decimal('cost_price_per_night', 10, 2)->comment('每晚成本價格');
            $table->decimal('total_cost_price', 10, 2)->comment('總成本價格');
            $table->text('remarks')->nullable()->comment('備註');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_hotels');
    }
};

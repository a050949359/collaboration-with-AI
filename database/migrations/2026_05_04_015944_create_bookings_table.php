<?php

use App\Enums\BookingStatus;
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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_reference')->unique()->comment('訂單參考號');
            $table->foreignId('passenger_id')->constrained()->comment('付款人 ID');
            $table->foreignId('tour_id')->constrained()->comment('行程 ID');
            $table->integer('number_of_travelers')->default(1)->comment('旅客人數');
            $table->decimal('discount_amount', 10, 2)->default(0)->comment('折扣金額');
            $table->decimal('final_amount', 10, 2)->default(0)->comment('最終金額');
            $table->string('status')->default(BookingStatus::Pending->value)->comment('訂單狀態');
            $table->text('remarks')->nullable()->comment('備註');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};

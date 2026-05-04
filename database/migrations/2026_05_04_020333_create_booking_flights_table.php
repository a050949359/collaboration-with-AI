<?php

use App\Enums\CabinClass;
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
        Schema::create('booking_flights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->comment('訂單 ID');
            $table->string('flight_number')->comment('航班號');
            $table->string('cabin_class')->default(CabinClass::Economy->value)->comment('艙等');
            $table->foreignId('origin_airport_id')->constrained('airports')->comment('出發地機場 ID');
            $table->foreignId('destination_airport_id')->constrained('airports')->comment('目的地機場 ID');
            $table->dateTime('departure_time')->comment('起飛時間');
            $table->dateTime('arrival_time')->comment('降落時間');
            $table->decimal('cost_price', 10, 2)->comment('成本價格');
            $table->text('remarks')->nullable()->comment('備註');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_flights');
    }
};

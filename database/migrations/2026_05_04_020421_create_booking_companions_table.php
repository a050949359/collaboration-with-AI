<?php

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
        Schema::create('booking_companions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->comment('訂單 ID');
            $table->foreignId('passenger_id')->constrained()->comment('同行人 ID');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_companions');
    }
};

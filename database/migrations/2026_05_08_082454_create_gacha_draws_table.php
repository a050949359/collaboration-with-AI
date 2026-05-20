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
        Schema::create('gacha_draws', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('gacha_rooms')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('gacha_players')->cascadeOnDelete();
            $table->foreignId('card_id')->nullable()->constrained('gacha_cards')->nullOnDelete();
            $table->json('result')->nullable()->comment('抽卡結果 {quality, code}');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gacha_draws');
    }
};

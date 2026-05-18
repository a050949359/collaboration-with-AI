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
        Schema::create('gacha_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('gacha_rooms')->cascadeOnDelete();
            $table->string('name')->comment('暱稱');
            $table->string('avatar')->nullable()->comment('頭像識別碼');
            $table->boolean('is_host')->default(false);
            $table->unsignedTinyInteger('level')->default(1)->comment('玩家等級');
            $table->unsignedSmallInteger('draws_used')->default(0)->comment('已抽次數');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gacha_players');
    }
};

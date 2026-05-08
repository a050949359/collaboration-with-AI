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
        Schema::create('gacha_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique()->comment('房間代碼');
            $table->string('room_name')->comment('房間名稱');
            $table->enum('status', ['waiting', 'playing', 'finished'])->default('waiting');
            $table->unsignedTinyInteger('max_players')->default(6);
            $table->unsignedTinyInteger('min_level')->default(1)->comment('最低等級限制');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gacha_rooms');
    }
};

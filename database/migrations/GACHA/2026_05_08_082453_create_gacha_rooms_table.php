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
            $table->enum('type', ['user', 'admin'])->default('user')->comment('user=玩家建立 admin=系統建立');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete()->comment('User room 建立者');
            $table->unsignedSmallInteger('draws_per_user')->default(0)->comment('每人抽卡上限，0=無限');
            $table->boolean('can_draw')->default(true)->comment('是否開放抽卡');
            $table->boolean('skip_anim')->default(false)->comment('跳過動畫');
            $table->boolean('is_ten_pull')->default(false)->comment('10連抽模式');
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

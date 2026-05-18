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
        Schema::create('story_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('story_sessions')->cascadeOnDelete();
            $table->foreignId('character_id')->nullable()->constrained('story_characters')->nullOnDelete(); // null = 旁白（外部事件）
            $table->text('content');                                                                        // LLM 或玩家產生的這輪內容
            $table->unsignedInteger('turn_number');                                                         // 全域輪次序號，StoryStateJob 遞增
            $table->boolean('is_player_written')->default(false);                                           // true = 真人輸入，非 LLM 生成
            $table->boolean('is_event')->default(false);                                                    // true = 外部事件（StoryEventJob 注入），前端以琥珀色標示
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('story_segments');
    }
};

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
        Schema::create('story_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('story_sessions')->cascadeOnDelete();
            $table->string('name');                                                                      // 角色名稱，帶入 LLM prompt 與 segment 顯示
            $table->text('persona');                                                                     // 個性、動機、秘密，帶入角色 LLM 的 system prompt
            $table->enum('type', ['llm', 'player', 'npc'])->default('llm');                             // llm：自動推進；player：等待真人輸入；npc：被動，不佔輪次
            $table->json('model_config')->nullable();                                                    // 覆蓋預設 model 設定（temperature 等），null 使用全域設定
            $table->unsignedTinyInteger('turn_order')->default(0);                                      // 輪次順序，StoryOrchestrateJob 依此推進
            $table->enum('status', ['active', 'unconscious', 'captured', 'dead'])->default('active');   // 非 active 時 StoryOrchestrateJob 自動跳過
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('story_characters');
    }
};

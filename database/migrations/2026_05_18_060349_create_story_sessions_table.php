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
        Schema::create('story_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('title');                                                          // 故事標題
            $table->json('setting');                                                          // 不可變世界規則（world + opening），每輪帶入 LLM prompt
            $table->text('world_state')->default('');                                         // 可變世界狀態摘要（≤1500字），由 StoryStateJob 每輪更新
            $table->unsignedBigInteger('current_character_id')->nullable();                   // 目前輪到的角色，StoryStateJob 推進
            $table->unsignedSmallInteger('advance_interval_minutes')->default(120);           // 各 job 間隔（分鐘）：角色與角色、最後角色到 StateJob、StateJob 到下一輪 Orchestrate
            $table->unsignedTinyInteger('rounds_per_advance')->default(1);                     // 每次推進跑幾輪（每輪含全部 narrator 角色），StoryOrchestrateJob 讀取後建 job chain
            $table->unsignedSmallInteger('rounds_without_progress')->default(0);             // 連續無實質推進輪數，達 3 時 StoryStateJob 設 needs_event=true
            $table->enum('status', ['active', 'paused', 'completed'])->default('paused');    // active：StoryClock 持續推進；paused/completed：跳過
            $table->enum('content_rating', ['general', 'mature'])->default('general');       // general：NSFW 輸出 [此處省略]；mature：暫留空待實作
            $table->timestamp('next_advance_at')->nullable();                                 // StoryClock 判斷是否到推進時間；dispatch 前立刻更新防重複
            $table->boolean('needs_event')->default(false);                                   // StoryStateJob 停滯偵測設 true，StoryOrchestrateJob 讀到走 EventJob
            $table->string('pending_scene_location')->nullable();                             // StoryStateJob 解析到新地點時寫入，StorySegmentJob 用完清空
            $table->unsignedInteger('state_last_turn')->nullable();                           // StoryStateJob 最後成功處理的 turn_number，用於判斷 world_state 是否為最新
            $table->boolean('needs_complete')->default(false);                                 // 用戶按下完結後設 true，引導 LLM 收尾；StateJob 到達 complete_deadline_turn 後設 status=completed
            $table->unsignedInteger('complete_deadline_turn')->nullable();                     // needs_complete 時設定，= 當下 max turn + 20
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('story_sessions');
    }
};

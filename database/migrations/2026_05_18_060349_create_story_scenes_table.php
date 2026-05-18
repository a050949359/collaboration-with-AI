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
        Schema::create('story_scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('story_sessions')->cascadeOnDelete();
            $table->string('location_name');               // 地點名稱，由 StoryStateJob 從 world_state 解析寫入 pending_scene_location
            $table->text('description');                   // generateScene() 產生的沉浸式場景描述（≤150字），帶入 StorySegmentJob 的 prompt
            $table->timestamp('first_visited_at');         // 首次到訪時間
            $table->timestamps();

            $table->unique(['session_id', 'location_name']); // 同故事同地點只生成一次，StorySegmentJob 用 firstOrCreate 複用
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('story_scenes');
    }
};

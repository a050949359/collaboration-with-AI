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
        Schema::create('story_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('story_sessions')->cascadeOnDelete();
            $table->string('name');                                                                            // 道具名稱
            $table->text('description');                                                                       // 道具描述，帶入角色 LLM prompt
            $table->foreignId('holder_character_id')->nullable()->constrained('story_characters')->nullOnDelete(); // null = 道具在世界某處，非角色持有
            $table->string('location_hint')->nullable();                                                       // 未被持有時的位置描述
            $table->boolean('is_preset')->default(false);                                                      // true = 建立故事時預設放入的關鍵道具
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('story_items');
    }
};

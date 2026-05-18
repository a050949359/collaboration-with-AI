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
            $table->string('name');
            $table->text('persona');
            $table->enum('type', ['llm', 'player', 'npc'])->default('llm');
            $table->json('model_config')->nullable();
            $table->unsignedTinyInteger('turn_order')->default(0);
            $table->enum('status', ['active', 'unconscious', 'captured', 'dead'])->default('active');
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

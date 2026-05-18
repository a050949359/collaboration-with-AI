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
            $table->foreignId('character_id')->nullable()->constrained('story_characters')->nullOnDelete();
            $table->text('content');
            $table->unsignedInteger('turn_number');
            $table->boolean('is_player_written')->default(false);
            $table->boolean('is_event')->default(false);
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

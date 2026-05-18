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
            $table->string('title');
            $table->json('setting');
            $table->text('world_state')->default('');
            $table->unsignedBigInteger('current_character_id')->nullable();
            $table->unsignedSmallInteger('advance_interval_minutes')->default(120);
            $table->unsignedSmallInteger('rounds_without_progress')->default(0);
            $table->enum('status', ['active', 'paused', 'completed'])->default('paused');
            $table->enum('content_rating', ['general', 'mature'])->default('general');
            $table->timestamp('next_advance_at')->nullable();
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

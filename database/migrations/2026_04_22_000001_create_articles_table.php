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
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('prompt')->nullable();
            $table->longText('content')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_url')->nullable();
            $table->string('content_status', 20)->default('pending');
            $table->string('image_status', 20)->default('pending');
            $table->text('content_error')->nullable();
            $table->text('image_error')->nullable();
            $table->timestamp('content_generated_at')->nullable();
            $table->timestamp('image_generated_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'content_status']);
            $table->index(['user_id', 'image_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};

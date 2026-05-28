<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('project', 64)->nullable()->index();
            $table->enum('status', ['todo', 'in_progress', 'done'])->default('todo')->index();
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete()->index();
            $table->timestamps();
        });

        Schema::create('task_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('content');
            $table->boolean('is_done')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_items');
        Schema::dropIfExists('tasks');
    }
};

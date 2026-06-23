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
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('persona', 500);
            $table->string('secret', 500)->nullable();
            $table->string('background', 500)->nullable();
            $table->json('appearance')->nullable();
            $table->string('outfit', 300)->nullable();
            $table->text('image_prompt')->nullable();
            $table->string('image_path')->nullable();   // 生成後的 webp 相對路徑（public disk）
            $table->string('image_url')->nullable();     // 對外可直連的圖片 URL（public 直連）
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};

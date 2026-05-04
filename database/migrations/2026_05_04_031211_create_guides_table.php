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
        Schema::create('guides', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('導遊姓名');
            $table->string('email')->unique()->comment('導遊電子郵件');
            $table->string('phone')->nullable()->comment('導遊電話');
            $table->string('country')->nullable()->comment('導遊國家');
            $table->string('language')->nullable()->comment('導遊語言');
            $table->text('remarks')->nullable()->comment('備註');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guides');
    }
};

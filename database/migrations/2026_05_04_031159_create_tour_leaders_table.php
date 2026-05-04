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
        Schema::create('tour_leaders', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('領隊姓名');
            $table->string('email')->unique()->comment('領隊電子郵件');
            $table->string('phone')->nullable()->comment('領隊電話');
            $table->string('license_number')->nullable()->comment('領隊執照號碼');
            $table->text('remarks')->nullable()->comment('備註');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tour_leaders');
    }
};

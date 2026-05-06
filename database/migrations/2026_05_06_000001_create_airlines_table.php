<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airlines', function (Blueprint $table) {
            $table->id();
            $table->string('iata', 4)->nullable()->unique()->comment('IATA 代碼');
            $table->string('icao', 4)->nullable()->comment('ICAO 代碼');
            $table->string('name_en')->comment('英文名稱');
            $table->string('name_zh_tw')->nullable()->comment('中文名稱');
            $table->string('alias_en')->nullable()->comment('英文別名');
            $table->string('alias_zh_tw')->nullable()->comment('中文別名');
            $table->string('nationality')->nullable()->comment('國籍');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airlines');
    }
};

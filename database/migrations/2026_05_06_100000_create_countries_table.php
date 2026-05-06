<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('code', 2)->unique()->comment('ISO 3166-1 alpha-2');
            $table->char('alpha3', 3)->nullable()->comment('ISO 3166-1 alpha-3');
            $table->string('numeric', 3)->nullable()->comment('ISO 3166-1 numeric');
            $table->string('name_en');
            $table->string('name_zh_tw')->nullable();
            $table->string('name_zh')->nullable();
            $table->string('capital')->nullable();
            $table->string('phone_code', 20)->nullable();
            $table->char('parent_code', 2)->nullable()->comment('parent country, e.g. GU -> US');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};

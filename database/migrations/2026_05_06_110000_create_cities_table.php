<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('wikidata_id')->nullable()->unique()->comment('Q12345');
            $table->string('name_en');
            $table->string('name_zh_tw')->nullable();
            $table->char('country_code', 2)->index();
            $table->decimal('latitude', 10, 6)->nullable();
            $table->decimal('longitude', 10, 6)->nullable();
            $table->unsignedInteger('population')->nullable();
            $table->string('timezone')->nullable();
            $table->integer('elevation')->nullable()->comment('metres');
            $table->unsignedInteger('area')->nullable()->comment('km²');
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->string('wikipedia_url')->nullable();
            $table->string('phone_code', 20)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};

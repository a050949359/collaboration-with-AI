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
        Schema::create('airports', function (Blueprint $table) {
            $table->id();
            $table->string('ident', 10)->unique();          // ICAO-style 識別碼，如 RCTP
            $table->enum('type', [
                'large_airport', 'medium_airport',
                'small_airport', 'heliport',
                'seaplane_base', 'closed'
            ]);
            $table->string('name');
            $table->decimal('latitude_deg', 10, 6)->nullable();
            $table->decimal('longitude_deg', 11, 6)->nullable();
            $table->integer('elevation_ft')->nullable();
            $table->string('continent', 2)->nullable();     // AS, EU, NA ...
            $table->string('iso_country', 2)->nullable();   // TW, JP, US ...
            $table->string('iso_region', 10)->nullable();   // TW-TPE ...
            $table->string('municipality')->nullable();
            $table->boolean('scheduled_service')->default(false);
            $table->string('icao_code', 10)->nullable();
            $table->string('iata_code', 3)->nullable();
            $table->string('gps_code', 10)->nullable();
            $table->string('local_code', 10)->nullable();
            $table->string('home_link')->nullable();
            $table->string('wikipedia_link')->nullable();
            $table->text('keywords')->nullable();
            $table->timestamps();

            $table->index(['iso_country', 'type']);
            $table->index(['continent', 'type']);
            $table->index(['latitude_deg', 'longitude_deg']);
            $table->index('scheduled_service');
            $table->index('iata_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airports');
    }
};

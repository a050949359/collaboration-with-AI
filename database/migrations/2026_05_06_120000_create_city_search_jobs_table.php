<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_search_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('city_name');
            $table->string('wikidata_qid', 20);
            $table->char('country_code', 2)->index();
            $table->enum('status', ['pending', 'processing', 'success', 'failed'])->default('pending');
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->text('error')->nullable();
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_search_jobs');
    }
};

<?php

use App\Enums\ExportType;
use App\Enums\ExportStatus;
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
        Schema::create('export_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default(ExportType::TOUR->value);
            $table->json('params')->default(json_encode([]));
            $table->string('status')->default(ExportStatus::PENDING->value);
            $table->string('file_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export_tasks');
    }
};

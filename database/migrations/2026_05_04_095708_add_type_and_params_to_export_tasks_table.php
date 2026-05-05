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
        Schema::table('export_tasks', function (Blueprint $table) {
            $table->string('type')->default('tour')->after('id');
            $table->json('params')->nullable()->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('export_tasks', function (Blueprint $table) {
            $table->dropColumn(['type', 'params']);
        });
    }
};

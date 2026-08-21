<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('territory_observation_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('entity_name', 20); // Wikidata QID
            // 不存 content/type：呼叫端只負責「觸發補齊這個 entity 的資料」，
            // 實際要寫哪些 type/value 是 worker 執行時自己打 Wiki 查出來的，事先不知道。
            $table->enum('status', ['pending', 'processing', 'success', 'failed'])->default('pending');
            $table->text('error')->nullable();
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('territory_observation_jobs');
    }
};

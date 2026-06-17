<?php

use App\Enums\Rag\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained('rag_knowledge_bases')->cascadeOnDelete();
            $table->string('drive_file_id')->comment('Google Drive 永久檔 ID');
            $table->string('name');
            $table->string('mime_type');
            $table->string('modified_time')->nullable()->comment('Drive modifiedTime,判斷是否變更');
            $table->string('status')->default(DocumentStatus::Draft->value);
            $table->timestamp('committed_at')->nullable();
            $table->unsignedInteger('committed_chunk_count')->nullable()->comment('上次落庫的塊數;null=從未落庫。dirty 時仍保留,供顯示「已落庫 N / 草稿 M」');
            $table->timestamps();

            $table->unique(['knowledge_base_id', 'drive_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_documents');
    }
};

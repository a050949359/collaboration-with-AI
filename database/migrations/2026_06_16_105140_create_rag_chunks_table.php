<?php

use App\Enums\Rag\ChunkStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('rag_documents')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->text('context')->nullable()->comment('Contextual Retrieval 情境前綴');
            $table->string('content_hash', 64)->comment('content 的 sha256,向量快取/去重比對用');
            $table->json('embedding')->nullable()->comment('向量快取,hash 不變則重用');
            $table->timestamp('embedded_at')->nullable();
            $table->string('status')->default(ChunkStatus::Draft->value);
            $table->timestamps();

            $table->index(['document_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_chunks');
    }
};

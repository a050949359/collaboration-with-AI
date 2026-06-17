<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_knowledge_bases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->comment('owner');
            $table->string('name')->comment('邏輯庫名,組 collection 用');
            $table->string('embedding_model')->comment('embedding 模型,collection 隔離用');
            $table->unsignedSmallInteger('dimensions')->comment('向量維度,collection 隔離用');
            $table->timestamps();

            $table->unique(['user_id', 'name', 'embedding_model', 'dimensions']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_knowledge_bases');
    }
};

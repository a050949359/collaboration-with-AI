<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained('rag_documents')->cascadeOnDelete();
            $table->foreignId('locked_by')->constrained('users')->cascadeOnDelete();
            $table->string('lock_token', 64)->comment('capability token,前端與其 LLM 共用');
            $table->timestamp('expires_at')->comment('租約到期,過期視為未鎖');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_locks');
    }
};

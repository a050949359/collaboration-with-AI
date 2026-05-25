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
        Schema::create('share_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('scope', 64);                     // 'about' | 'mcp' | 'mcp:read' ...
            $table->unsignedInteger('max_uses')->nullable(); // null = 無限，1 = 一次性
            $table->unsignedInteger('uses_count')->default(0);
            $table->string('note')->nullable();              // 備註用途，僅供管理者參考
            $table->timestamp('expires_at')->nullable();
            $table->string('line_user_id', 64)->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('share_tokens');
    }
};

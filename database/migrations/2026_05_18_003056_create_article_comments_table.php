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
        Schema::create('article_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->onDelete('cascade'); // 刪除文章時刪除評論
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // 允許匿名評論，刪除用戶時不刪除評論
            $table->string('guest_name')->nullable();
            $table->uuid('guest_id')->nullable()->index(); // UUID，訪客專用
            $table->foreignId('parent_id')->nullable()->constrained('article_comments')->nullOnUpdate(); // 父評論，允許 null
            $table->text('body');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_comments');
    }
};

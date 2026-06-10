<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_entities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->unique();
            $table->string('type', 64);
            $table->timestamps();
        });

        Schema::create('mcp_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('mcp_entities')->cascadeOnDelete();
            $table->text('content');
            // typed observation：預設 desc（純文字描述）；geo/ip 等結構化資料用其他 type
            $table->string('type', 32)->default('desc');
            $table->timestamps();

            $table->index(['entity_id', 'type']);
        });

        Schema::create('mcp_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_entity_id')->constrained('mcp_entities')->cascadeOnDelete();
            $table->foreignId('to_entity_id')->constrained('mcp_entities')->cascadeOnDelete();
            $table->string('relation_type', 64);
            $table->timestamps();

            $table->unique(['from_entity_id', 'to_entity_id', 'relation_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_relations');
        Schema::dropIfExists('mcp_observations');
        Schema::dropIfExists('mcp_entities');
    }
};

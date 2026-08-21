<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('territory_entities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->unique();
            $table->string('type', 64);
            $table->timestamps();
        });

        Schema::create('territory_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('territory_entities')->cascadeOnDelete();
            $table->text('content');
            $table->string('type', 32)->default('desc');
            $table->timestamps();

            $table->index(['entity_id', 'type']);
        });

        Schema::create('territory_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_entity_id')->constrained('territory_entities')->cascadeOnDelete();
            $table->foreignId('to_entity_id')->constrained('territory_entities')->cascadeOnDelete();
            // 慣例 relation_type：part_of（子節點屬於某父節點）。
            // 唯一三元組允許同一節點同時有多條 part_of，指向不同的平行治理單位。
            $table->string('relation_type', 64);
            $table->timestamps();

            $table->unique(['from_entity_id', 'to_entity_id', 'relation_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('territory_relations');
        Schema::dropIfExists('territory_observations');
        Schema::dropIfExists('territory_entities');
    }
};

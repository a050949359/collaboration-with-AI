<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gacha_deck_cards', function (Blueprint $table) {
            $table->foreignId('deck_id')->constrained('gacha_decks')->cascadeOnDelete();
            $table->foreignId('card_id')->constrained('gacha_cards')->cascadeOnDelete();
            $table->primary(['deck_id', 'card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gacha_deck_cards');
    }
};

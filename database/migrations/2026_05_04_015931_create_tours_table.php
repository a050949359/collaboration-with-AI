<?php

use App\Enums\TourType;
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
        Schema::create('tours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_leader_id')->constrained()->comment('領隊 ID');
            $table->string('type')->default(TourType::Fit->value)->comment('行程類型');
            $table->string('code')->unique()->comment('行程代碼');
            $table->string('name')->comment('行程名稱');
            $table->date('departure_date')->comment('出發日期');
            $table->date('return_date')->comment('回程日期');
            $table->integer('duration')->comment('行程天數');
            $table->decimal('selling_price', 10, 2)->default(0)->comment('售價');
            $table->decimal('target_profit', 10, 2)->default(0)->comment('目標利潤');
            $table->unsignedInteger('min_pax')->default(10)->comment('最少成行人數');
            $table->unsignedInteger('max_pax')->default(40)->comment('最多成行人數');
            $table->text('remarks')->nullable()->comment('備註');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tours');
    }
};

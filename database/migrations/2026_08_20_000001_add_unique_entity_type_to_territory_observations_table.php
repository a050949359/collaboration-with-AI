<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ⚠️ 部署順序依賴：country 層目前每個 entity 底下有多筆 type='desc' 的 observation
// （add_observation 目前不接受 type 參數，全部落在預設值），在任何還沒跑過手動
// backfill（把 content 的 "key: value" 拆成 type+content，見 project memory）的環境上，
// 這個 unique 約束會直接建立失敗。正式站部署此 migration 前，務必先完成 backfill。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('territory_observations', function (Blueprint $table) {
            $table->dropIndex(['entity_id', 'type']);
            // unique（非單純 index）：避免 WriteTerritoryObservationJob 併發跑同一 entity 時，
            // delete+create 交錯造成同一 type 留下重複列。
            $table->unique(['entity_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('territory_observations', function (Blueprint $table) {
            $table->dropUnique(['entity_id', 'type']);
            $table->index(['entity_id', 'type']);
        });
    }
};

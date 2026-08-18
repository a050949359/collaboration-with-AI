<?php

namespace App\Models\Territory;

use App\Enums\Territory\ObservationJobStatus;
use App\Jobs\WriteTerritoryObservationJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $entity_name
 * @property ObservationJobStatus $status
 * @property string|null $error
 * @property int $submitted_by
 */
class TerritoryObservationJob extends Model
{
    protected $fillable = ['entity_name', 'status', 'error', 'submitted_by'];

    protected $casts = ['status' => ObservationJobStatus::class];

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    // MCP tool（api-key 觸發）與 REST controller（未來 web 介面用）共用同一套
    // 「建 job 記錄 + dispatch queue job」邏輯，避免兩處各寫一份容易日後改一邊漏改一邊。
    public static function queue(string $entityName, int $submittedBy): self
    {
        // 同一 QID 若已經有 pending/processing 的 job，直接回傳既有的，不重複入隊——
        // 從源頭避免同一 entity 被兩個 job 同時處理而互相踩到彼此寫入的 observation。
        // 這裡是 select 後 create，仍有極短暫的 TOCTOU 空隙，但已足以擋掉正常操作下
        // （手動觸發、MCP 呼叫、批次腳本依序呼叫）會遇到的重複入隊情境。
        $existing = self::where('entity_name', $entityName)
            ->whereIn('status', [ObservationJobStatus::Pending, ObservationJobStatus::Processing])
            ->first();
        if ($existing) {
            return $existing;
        }

        $job = self::create([
            'entity_name' => $entityName,
            'status' => ObservationJobStatus::Pending,
            'submitted_by' => $submittedBy,
        ]);

        WriteTerritoryObservationJob::dispatch($job->id);

        return $job;
    }
}

<?php

namespace App\Models\Territory;

use App\Jobs\WriteTerritoryObservationJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $entity_name
 * @property string $status
 * @property string|null $error
 * @property int $submitted_by
 */
class TerritoryObservationJob extends Model
{
    protected $fillable = ['entity_name', 'status', 'error', 'submitted_by'];

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    // MCP tool（api-key 觸發）與 REST controller（未來 web 介面用）共用同一套
    // 「建 job 記錄 + dispatch queue job」邏輯，避免兩處各寫一份容易日後改一邊漏改一邊。
    public static function queue(string $entityName, int $submittedBy): self
    {
        $job = self::create([
            'entity_name' => $entityName,
            'status' => 'pending',
            'submitted_by' => $submittedBy,
        ]);

        WriteTerritoryObservationJob::dispatch($job->id);

        return $job;
    }
}

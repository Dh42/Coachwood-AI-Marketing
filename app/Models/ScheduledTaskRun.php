<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of a scheduled task, by the scheduler or by a person.
 */
#[Fillable(['command', 'source', 'user_id', 'status', 'started_at', 'finished_at', 'runtime_ms', 'exit_code', 'error', 'output'])]
class ScheduledTaskRun extends Model
{
    public const SCHEDULE = 'schedule';

    public const MANUAL = 'manual';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const OK = 'ok';

    public const FAILED = 'failed';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDone(): bool
    {
        return in_array($this->status, [self::OK, self::FAILED], true);
    }
}

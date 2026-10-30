<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One shape of a run: the prompt as sent to the model, how many images
 * were asked for, how many came back, and what they cost.
 */
#[Fillable(['request_id', 'source_output_id', 'user_id', 'ratio', 'image_model', 'prompt', 'instruction', 'change', 'size', 'quality', 'count', 'done', 'failed', 'status', 'cost', 'error', 'compose_error', 'started_at', 'finished_at', 'prompt_revision_id', 'addition_revision_id'])]
class Generation extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return ['cost' => 'float', 'change' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<CreativeRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(CreativeRequest::class, 'request_id');
    }

    /**
     * The image a change was asked on; null for the run's own generations and a shape run again.
     *
     * @return BelongsTo<Output, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Output::class, 'source_output_id');
    }

    /**
     * Who asked for this generation after the run; null for the run itself.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isChange(): bool
    {
        return $this->source_output_id !== null;
    }

    /**
     * Still queued or running: the page keeps refreshing.
     */
    public function isBusy(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /**
     * @return HasMany<Output, $this>
     */
    public function outputs(): HasMany
    {
        return $this->hasMany(Output::class)->where('kind', Output::IMAGE)->orderBy('position');
    }

    /**
     * The finished ads made from this shape's images.
     *
     * @return HasMany<Output, $this>
     */
    public function ads(): HasMany
    {
        return $this->hasMany(Output::class)->where('kind', Output::AD)->orderBy('placement_id')->orderBy('parent_id')->orderBy('position');
    }

    public function model(): ?ImageModel
    {
        return ImageModel::findByKey($this->image_model);
    }

    public function isFinished(): bool
    {
        return $this->done + $this->failed >= $this->count;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::QUEUED => $this->isChange() ? 'Changing…' : 'Waiting',
            self::RUNNING => $this->isChange() ? 'Changing…' : "{$this->done} of {$this->count}",
            self::DONE => $this->failed > 0 ? "{$this->done} of {$this->count} made" : 'Done',
            self::FAILED => 'Failed',
            default => ucfirst($this->status),
        };
    }
}

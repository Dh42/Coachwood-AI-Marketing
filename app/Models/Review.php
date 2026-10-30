<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The reviewer's verdict on one output: a score per criterion, the
 * weighted overall, any hard fails, and why in one line.
 */
#[Fillable(['output_id', 'model', 'scores', 'overall', 'hard_fails', 'reason', 'cost', 'status', 'error', 'prompt_revision_id', 'addition_revision_id'])]
class Review extends Model
{
    public const DONE = 'done';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return ['scores' => 'array', 'hard_fails' => 'array', 'cost' => 'float'];
    }

    /**
     * @return BelongsTo<Output, $this>
     */
    public function output(): BelongsTo
    {
        return $this->belongsTo(Output::class);
    }
}

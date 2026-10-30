<?php

namespace App\Models;

use App\Prompts\PromptType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A brand's prompt of one type (user_id null), or one person's additions
 * to it (user_id set). The text itself lives in its revisions; the current
 * one is pointed at.
 */
#[Fillable(['brand_id', 'type', 'user_id', 'current_revision_id'])]
class Prompt extends Model
{
    protected function casts(): array
    {
        return ['type' => PromptType::class];
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PromptRevision, $this>
     */
    public function current(): BelongsTo
    {
        return $this->belongsTo(PromptRevision::class, 'current_revision_id');
    }

    /**
     * @return HasMany<PromptRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(PromptRevision::class);
    }

    public function isAddition(): bool
    {
        return $this->user_id !== null;
    }

    public function body(): string
    {
        return (string) ($this->current?->body ?? '');
    }
}

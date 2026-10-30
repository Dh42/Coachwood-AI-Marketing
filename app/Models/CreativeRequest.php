<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ask for creative: the brief as typed, the placements ticked, what
 * the planner asked and was told, the plan it made and what that will
 * cost. Public to the team or private to its creator and admins.
 */
#[Fillable(['brand_id', 'user_id', 'status', 'title', 'brief', 'languages', 'output', 'ad_style', 'copy', 'placement_ids', 'per_ratio', 'deliver', 'image_model',
    'product_variant_id', 'public', 'questions', 'answers', 'rounds', 'plan', 'image_cost', 'estimated_cost', 'actual_cost', 'error', 'planned_at', 'confirmed_at', 'batch_id', 'finished_at', 'prompt_revision_id', 'addition_revision_id'])]
class CreativeRequest extends Model
{
    protected $table = 'requests';

    /** The planner is reading the brief (a queued job). */
    public const PLANNING = 'planning';

    /** The planner has questions; waiting on the person. */
    public const ASKING = 'asking';

    /** The plan is ready to confirm. */
    public const PLANNED = 'planned';

    /** Confirmed within budget; the generation jobs take it from here. */
    public const READY = 'ready';

    /** The images are being made. */
    public const RUNNING = 'running';

    /** Every image is made (or failed); the outputs are on the page. */
    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    public const OUTPUTS = ['ad' => 'Finished ads', 'image' => 'Clean images', 'both' => 'Both'];

    public const AD_STYLES = ['composed' => 'Composed: the app sets the words, logo and price on the image', 'generated' => 'Generated: the image model draws the words into the picture'];

    public const COPY = ['none' => 'No copy', 'captions' => 'Captions only', 'ad' => 'Ad copy only', 'all' => 'Captions and ad copy'];

    public const LANGUAGES = ['en' => 'English', 'fr' => 'French'];

    protected function casts(): array
    {
        return [
            'languages' => 'array', 'placement_ids' => 'array', 'questions' => 'array', 'answers' => 'array', 'plan' => 'array',
            'public' => 'boolean', 'image_cost' => 'float', 'estimated_cost' => 'float', 'actual_cost' => 'float',
            'planned_at' => 'datetime', 'confirmed_at' => 'datetime', 'finished_at' => 'datetime',
        ];
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
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return HasMany<RequestTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(RequestTarget::class, 'request_id')->orderBy('id');
    }

    /**
     * @return HasMany<RequestReference, $this>
     */
    public function references(): HasMany
    {
        return $this->hasMany(RequestReference::class, 'request_id')->orderBy('id');
    }

    /**
     * @return HasMany<Generation, $this>
     */
    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class, 'request_id')->orderBy('id');
    }

    /**
     * @return HasMany<Output, $this>
     */
    public function outputs(): HasMany
    {
        return $this->hasMany(Output::class, 'request_id')->orderBy('generation_id')->orderBy('position');
    }

    /**
     * @return HasMany<Copy, $this>
     */
    public function copies(): HasMany
    {
        return $this->hasMany(Copy::class, 'request_id')->orderBy('placement_id')->orderBy('language');
    }

    /**
     * @return HasMany<Spend, $this>
     */
    public function spend(): HasMany
    {
        return $this->hasMany(Spend::class, 'request_id');
    }

    /**
     * Requests a person may see in a brand: their own, public ones, and
     * everything for those who see all.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, Brand $brand): void
    {
        $query->where('brand_id', $brand->id);

        if (! $user->hasPermission('requests.view_all', $brand->id)) {
            $query->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('public', true));
        }
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * Something is happening in the background: the page keeps refreshing.
     */
    public function isWaiting(): bool
    {
        return in_array($this->status, [self::PLANNING, self::READY, self::RUNNING], true);
    }

    /**
     * The run, a change or a shape run again is still going.
     */
    public function isBusy(): bool
    {
        return $this->isWaiting() || $this->generations()->whereIn('status', [Generation::QUEUED, Generation::RUNNING])->exists();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PLANNING, self::ASKING, self::PLANNED], true);
    }

    /**
     * Reserved against budgets: confirmed and not yet finished.
     */
    public function isReserving(): bool
    {
        return in_array($this->status, [self::READY, self::RUNNING], true);
    }

    public function hasRun(): bool
    {
        return in_array($this->status, [self::RUNNING, self::DONE], true) || ($this->status === self::FAILED && $this->confirmed_at !== null);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PLANNING => 'Planning',
            self::ASKING => 'Needs an answer',
            self::PLANNED => 'Plan ready',
            self::READY => 'Starting',
            self::RUNNING => 'Generating',
            self::DONE => 'Done',
            self::CANCELLED => 'Cancelled',
            self::FAILED => 'Failed',
            default => ucfirst($this->status),
        };
    }

    public function plan(string $key, mixed $default = null): mixed
    {
        return data_get($this->plan, $key, $default);
    }

    /**
     * @return list<string>
     */
    public function languageNames(): array
    {
        return array_map(fn ($l) => self::LANGUAGES[$l] ?? $l, $this->languages ?? ['en']);
    }
}

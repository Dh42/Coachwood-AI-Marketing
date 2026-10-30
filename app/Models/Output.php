<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * One image that came back from a generation, on the app's disk, with a
 * thumbnail for the screens.
 */
#[Fillable(['request_id', 'generation_id', 'parent_id', 'source_id', 'placement_id', 'layout_template_id', 'ratio', 'kind', 'language', 'position', 'path', 'thumb_path', 'mime', 'width', 'height', 'size', 'served_by', 'cost', 'status', 'checks', 'score', 'reason', 'removed_at'])]
class Output extends Model
{
    public const IMAGE = 'image';

    /** A finished ad: a clean image with the words, logo, product and button set on it for one placement. */
    public const AD = 'ad';

    protected function casts(): array
    {
        return ['cost' => 'float', 'checks' => 'array', 'removed_at' => 'datetime'];
    }

    /**
     * The clean image a finished ad was made from.
     *
     * @return BelongsTo<Output, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Output::class, 'parent_id');
    }

    /**
     * The image this one is a changed version of ("Make a change").
     *
     * @return BelongsTo<Output, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Output::class, 'source_id');
    }

    /**
     * Changed versions made from this image, oldest first.
     *
     * @return HasMany<Output, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Output::class, 'source_id')->orderBy('id');
    }

    /**
     * The original image a chain of changes started from (itself when it wasn't changed).
     */
    public function root(): Output
    {
        $output = $this;
        for ($hops = 0; $output->source_id && $hops < 50; $hops++) {
            $output = $output->source;
        }

        return $output;
    }

    /**
     * How many changes lie between this image and the original: 0 for the original.
     */
    public function depth(): int
    {
        $depth = 0;
        for ($output = $this; $output->source_id && $depth < 50; $depth++) {
            $output = $output->source;
        }

        return $depth;
    }

    /**
     * This image and every changed version made from it, however many
     * steps down, oldest first.
     *
     * @return list<Output>
     */
    public function tree(): array
    {
        $all = [$this];
        foreach ($this->versions()->get() as $version) {
            array_push($all, ...$version->tree());
        }

        return $all;
    }

    /**
     * The finished ads made from this clean image.
     *
     * @return HasMany<Output, $this>
     */
    public function ads(): HasMany
    {
        return $this->hasMany(Output::class, 'parent_id')->where('kind', self::AD)->orderBy('placement_id')->orderBy('language');
    }

    /**
     * @return BelongsTo<Placement, $this>
     */
    public function placement(): BelongsTo
    {
        return $this->belongsTo(Placement::class);
    }

    /**
     * @return HasOne<Review, $this>
     */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function isShortlisted(): bool
    {
        return $this->status === 'shortlisted';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Its files were deleted by the nightly clean-up of rejects; the row stays for the record.
     */
    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    public function isAd(): bool
    {
        return $this->kind === self::AD;
    }

    /**
     * Whether every deterministic check passed (size, file size, safe zone).
     */
    public function passedChecks(): bool
    {
        return collect($this->checks ?? [])->every(fn ($check) => ($check['ok'] ?? true) === true);
    }

    /**
     * The checks that failed, in words.
     *
     * @return list<string>
     */
    public function failedChecks(): array
    {
        return collect($this->checks ?? [])->filter(fn ($check) => ($check['ok'] ?? true) !== true)->pluck('note')->filter()->values()->all();
    }

    /**
     * @return BelongsTo<CreativeRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(CreativeRequest::class, 'request_id');
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    public function url(): string
    {
        return route('requests.output', [$this->request->brand, $this->request, $this]);
    }

    public function thumbUrl(): string
    {
        return $this->thumb_path ? route('requests.output', [$this->request->brand, $this->request, $this, 'thumb']) : $this->url();
    }

    public function extension(): string
    {
        return match ($this->mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    /**
     * "whey-isolate-20-off-weekend-4x5-3.png"
     */
    public function downloadName(): string
    {
        $what = $this->isAd() && $this->placement ? $this->placement->label().' '.$this->language : str_replace(':', 'x', $this->ratio);

        return Str::slug(($this->request->title ?: 'image').' '.$what.' '.$this->position).'.'.$this->extension();
    }
}

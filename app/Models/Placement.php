<?php

namespace App\Models;

use App\Platforms\AspectRatio;
use App\Platforms\CopyField;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One placement on a platform (Instagram Feed 4:5, TikTok In-feed…): its
 * pixel size, aspect ratio, safe zone, file rules, video length range and
 * copy limits. The composer, the checks and the copy writer read these.
 */
#[Fillable(['platform_id', 'key', 'name', 'width', 'height', 'ratio', 'formats', 'safe_top', 'safe_bottom', 'safe_left', 'safe_right',
    'image_types', 'max_image_kb', 'video_types', 'max_video_mb', 'video_min_seconds', 'video_max_seconds', 'copy_limits', 'notes', 'enabled', 'position'])]
class Placement extends Model
{
    public const IMAGE = 'image';

    public const VIDEO = 'video';

    public const IMAGE_TYPES = ['jpg', 'png', 'webp', 'gif'];

    public const VIDEO_TYPES = ['mp4', 'mov', 'webm'];

    protected function casts(): array
    {
        return ['formats' => 'array', 'image_types' => 'array', 'video_types' => 'array', 'copy_limits' => 'array', 'enabled' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (Placement $placement) {
            $placement->ratio = AspectRatio::of((int) $placement->width, (int) $placement->height);
        });
    }

    /**
     * @return BelongsTo<Platform, $this>
     */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    /**
     * Placements that can be picked: switched on, on a platform that's switched on.
     *
     * @param  Builder<Placement>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('enabled', true)->whereHas('platform', fn ($q) => $q->where('enabled', true));
    }

    public function takes(string $format): bool
    {
        return in_array($format, $this->formats ?? [], true);
    }

    public function takesImages(): bool
    {
        return $this->takes(self::IMAGE);
    }

    public function takesVideo(): bool
    {
        return $this->takes(self::VIDEO);
    }

    public function sizeLabel(): string
    {
        return "{$this->width} × {$this->height}";
    }

    /**
     * "Instagram · Feed (4:5)".
     */
    public function label(): string
    {
        return "{$this->platform->name} · {$this->name}";
    }

    public function hasSafeZone(): bool
    {
        return $this->safe_top + $this->safe_bottom + $this->safe_left + $this->safe_right > 0;
    }

    /**
     * The area nothing important may sit in, as pixels from each edge.
     *
     * @return array{top: int, bottom: int, left: int, right: int}
     */
    public function safeZone(): array
    {
        return ['top' => (int) $this->safe_top, 'bottom' => (int) $this->safe_bottom, 'left' => (int) $this->safe_left, 'right' => (int) $this->safe_right];
    }

    /**
     * The copy this placement takes and how long each piece may be.
     *
     * @return array<string, int> CopyField value => limit
     */
    public function copyLimits(): array
    {
        return collect($this->copy_limits ?? [])->filter(fn ($limit, $field) => CopyField::tryFrom($field) && (int) $limit > 0)->map(fn ($l) => (int) $l)->all();
    }

    public function copyLimit(CopyField $field): ?int
    {
        return $this->copyLimits()[$field->value] ?? null;
    }

    public function isPortrait(): bool
    {
        return AspectRatio::isPortrait($this->ratio);
    }
}

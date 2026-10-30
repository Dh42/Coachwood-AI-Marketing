<?php

namespace App\Models;

use App\Platforms\CopyField;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Copy for one placement in one language (several versions, each checked
 * against the placement's limits), or the words set on a request's
 * finished ads in one language.
 */
#[Fillable(['request_id', 'placement_id', 'language', 'kind', 'versions', 'model', 'cost', 'status', 'error', 'prompt_revision_id', 'addition_revision_id'])]
class Copy extends Model
{
    protected $table = 'copies';

    public const PLACEMENT = 'placement';

    public const AD = 'ad';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return ['versions' => 'array', 'cost' => 'float'];
    }

    /**
     * @return BelongsTo<CreativeRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(CreativeRequest::class, 'request_id');
    }

    /**
     * @return BelongsTo<Placement, $this>
     */
    public function placement(): BelongsTo
    {
        return $this->belongsTo(Placement::class);
    }

    /**
     * The words for a finished ad: headline, subline, cta.
     *
     * @return array{headline: string, subline: string, cta: string}|null
     */
    public function adText(): ?array
    {
        $v = $this->versions[0] ?? null;

        return $this->kind === self::AD && $v ? ['headline' => (string) ($v['headline'] ?? ''), 'subline' => (string) ($v['subline'] ?? ''), 'cta' => (string) ($v['cta'] ?? '')] : null;
    }

    /**
     * Whether every version fits its limits.
     */
    public function fits(): bool
    {
        return collect($this->versions ?? [])->every(fn ($v) => collect($v['checks'] ?? [])->every(fn ($c) => $c['ok']));
    }

    /**
     * Counts a piece the way its limit does: hashtags by number, the rest by characters.
     */
    public static function count(CopyField $field, mixed $value): int
    {
        if ($field === CopyField::Hashtags) {
            return count(array_filter(is_array($value) ? $value : (preg_split('/\s+/', trim((string) $value)) ?: [])));
        }

        return mb_strlen(trim((string) $value));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A platform creative is made for (Instagram, TikTok, Google Ads…), with
 * its placements. A row in the library, never code: when a platform changes
 * a spec, someone changes the row.
 */
#[Fillable(['key', 'name', 'prompt_note', 'enabled', 'position', 'verified_at', 'verified_by'])]
class Platform extends Model
{
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'verified_at' => 'datetime'];
    }

    /**
     * @return HasMany<Placement, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(Placement::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Whether someone has checked its specs against the platform's own docs.
     */
    public function isChecked(): bool
    {
        return $this->verified_at !== null;
    }
}

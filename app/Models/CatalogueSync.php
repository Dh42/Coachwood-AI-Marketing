<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One read of a brand's store: when, how it went, and what it found.
 */
#[Fillable(['brand_id', 'connection_id', 'source', 'status', 'started_at', 'finished_at', 'products', 'added', 'changed', 'removed', 'error', 'user_id'])]
class CatalogueSync extends Model
{
    public const RUNNING = 'running';

    public const OK = 'ok';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
}

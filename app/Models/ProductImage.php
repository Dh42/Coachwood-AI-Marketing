<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product photo on the store, by its address there. A cut-out (background
 * removed) is kept beside it once made (cutout_path, on the files disk);
 * when the last attempt failed, why is kept in cutout_error.
 */
#[Fillable(['product_id', 'external_id', 'url', 'alt', 'position', 'width', 'height', 'cutout_path', 'cutout_error', 'removed_at'])]
class ProductImage extends Model
{
    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function hasCutout(): bool
    {
        return $this->cutout_path !== null;
    }
}

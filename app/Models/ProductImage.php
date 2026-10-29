<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product photo on the store, by its address there. A cut-out (background
 * removed) is kept beside it once made (cutout_path, on the files disk).
 */
#[Fillable(['product_id', 'external_id', 'url', 'alt', 'position', 'width', 'height', 'cutout_path', 'removed_at'])]
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
}

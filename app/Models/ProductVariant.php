<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One size or flavour of a product, with its real packaging size (typed in
 * by the team) so two pack shots in one frame are scaled to each other.
 */
#[Fillable(['product_id', 'external_id', 'title', 'sku', 'price', 'currency', 'available', 'options', 'position', 'product_image_id', 'width_mm', 'height_mm', 'depth_mm', 'dimensions_by', 'removed_at'])]
class ProductVariant extends Model
{
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'available' => 'boolean',
            'price' => 'decimal:2',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The variant's own image, when the store gives it one.
     *
     * @return BelongsTo<ProductImage, $this>
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'product_image_id');
    }

    public function hasDimensions(): bool
    {
        return $this->width_mm !== null && $this->height_mm !== null;
    }

    /**
     * "1 kg · Vanilla", from the store's option values.
     */
    public function label(): string
    {
        $values = array_values(array_filter((array) $this->options, fn ($v) => $v !== 'Default Title'));

        return $values ? implode(' · ', $values) : ($this->title === 'Default Title' ? 'Only size' : $this->title);
    }
}

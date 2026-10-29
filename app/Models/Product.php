<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product copied from the brand's store (App\Catalogue). One that
 * disappears from the store is kept with removed_at set, so old requests
 * still know what they were about.
 */
#[Fillable(['brand_id', 'connection_id', 'external_id', 'handle', 'title', 'type', 'vendor', 'status', 'url', 'description_html', 'tags', 'options', 'details', 'synced_at', 'removed_at'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'options' => 'array',
            'details' => 'array',
            'synced_at' => 'datetime',
            'removed_at' => 'datetime',
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
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /**
     * On the store and showing to shoppers.
     */
    public function isLive(): bool
    {
        return $this->removed_at === null && $this->status === 'active' && filled($this->url);
    }
}

<?php

namespace App\Models;

use App\BrandKit\Kind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item of a brand kit: a logo, a colour, a font, a reference image, a
 * CTA… Its kind says which fields `data` holds and whether it has a file.
 */
#[Fillable(['brand_id', 'kind', 'position', 'data', 'path', 'thumb_path', 'original_name', 'mime', 'size', 'width', 'height', 'product_variant_id', 'created_by'])]
class BrandKitItem extends Model
{
    protected function casts(): array
    {
        return ['kind' => Kind::class, 'data' => 'array'];
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * A field's stored value.
     */
    public function value(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function label(): string
    {
        return $this->kind->label($this);
    }

    public function hasFile(): bool
    {
        return $this->path !== null;
    }

    public function isImage(): bool
    {
        return $this->path !== null && str_starts_with((string) $this->mime, 'image/');
    }

    public function isSvg(): bool
    {
        return $this->mime === 'image/svg+xml';
    }

    /**
     * Whether the image was uploaded with a transparent background (null when unknown).
     */
    public function isTransparent(): ?bool
    {
        return $this->data['transparent'] ?? null;
    }

    public function url(): ?string
    {
        return $this->path ? route('brand-kit.file', ['brand' => $this->brand, 'item' => $this]) : null;
    }

    /**
     * A small preview for the page, or the file itself when none was made (an SVG, a font, a track).
     */
    public function thumbUrl(): ?string
    {
        return $this->thumb_path ? route('brand-kit.file', ['brand' => $this->brand, 'item' => $this, 'size' => 'thumb']) : $this->url();
    }
}

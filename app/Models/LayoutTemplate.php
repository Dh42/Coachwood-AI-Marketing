<?php

namespace App\Models;

use App\Platforms\AspectRatio;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where the headline, sub-line, logo, product, button and fine print sit
 * on a finished ad of one shape, as a handful of plain choices the
 * composer turns into a layout at any pixel size.
 */
#[Fillable(['brand_id', 'ratio', 'name', 'is_default', 'settings', 'position'])]
class LayoutTemplate extends Model
{
    public const OPTIONS = [
        'headline_position' => ['top' => 'Top', 'middle' => 'Middle', 'bottom' => 'Bottom'],
        'align' => ['left' => 'Left', 'center' => 'Centred'],
        'text_size' => ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'],
        'text_colour' => ['white' => 'White', 'dark' => 'Dark', 'primary' => 'The brand\'s primary colour'],
        'overlay' => ['none' => 'None', 'top' => 'Darken the top', 'bottom' => 'Darken the bottom', 'both' => 'Darken top and bottom'],
        'logo_corner' => ['none' => 'No logo', 'top-left' => 'Top left', 'top-right' => 'Top right', 'bottom-left' => 'Bottom left', 'bottom-right' => 'Bottom right'],
        'logo_size' => ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'],
        'product_position' => ['none' => 'No product', 'left' => 'Left', 'right' => 'Right', 'bottom' => 'Bottom, centred'],
        'product_size' => ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'],
        'cta' => ['none' => 'No button', 'button' => 'A button', 'text' => 'Plain text'],
    ];

    public const TOGGLES = ['subline' => 'Show the offer details under the headline', 'legal' => 'Show the brand\'s legal line', 'price_badge' => 'Show the price in a badge'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'is_default' => 'boolean'];
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function setting(string $key): mixed
    {
        return $this->settings[$key] ?? self::defaults($this->ratio)[$key] ?? null;
    }

    /**
     * A sensible layout for a shape: words up top and the product below on
     * a story, words beside the product on a feed or a banner.
     *
     * @return array<string, mixed>
     */
    public static function defaults(string $ratio): array
    {
        $value = AspectRatio::value($ratio);

        if ($value < 0.9) {
            return ['headline_position' => 'top', 'align' => 'center', 'text_size' => 'large', 'text_colour' => 'white', 'overlay' => 'both',
                'logo_corner' => 'top-left', 'logo_size' => 'medium', 'product_position' => 'bottom', 'product_size' => 'large', 'cta' => 'button',
                'subline' => true, 'legal' => false, 'price_badge' => false];
        }

        if ($value > 1.6) {
            return ['headline_position' => 'middle', 'align' => 'left', 'text_size' => 'medium', 'text_colour' => 'white', 'overlay' => 'bottom',
                'logo_corner' => 'top-left', 'logo_size' => 'small', 'product_position' => 'right', 'product_size' => 'large', 'cta' => 'button',
                'subline' => true, 'legal' => false, 'price_badge' => false];
        }

        return ['headline_position' => 'bottom', 'align' => 'left', 'text_size' => 'medium', 'text_colour' => 'white', 'overlay' => 'bottom',
            'logo_corner' => 'top-left', 'logo_size' => 'medium', 'product_position' => 'right', 'product_size' => 'medium', 'cta' => 'button',
            'subline' => true, 'legal' => false, 'price_badge' => false];
    }

    /**
     * A template that isn't saved: the defaults for a shape, used when the brand hasn't set one up.
     */
    public static function builtIn(Brand $brand, string $ratio): self
    {
        return new self(['brand_id' => $brand->id, 'ratio' => $ratio, 'name' => 'Standard', 'is_default' => true, 'settings' => self::defaults($ratio)]);
    }

    /**
     * The layout in a few words.
     */
    public function summary(): string
    {
        $o = self::OPTIONS;
        $parts = ['Headline '.strtolower($o['headline_position'][$this->setting('headline_position')] ?? '').', '.strtolower($o['align'][$this->setting('align')] ?? '')];
        $parts[] = $this->setting('product_position') === 'none' ? 'no product' : 'product '.strtolower($o['product_position'][$this->setting('product_position')] ?? '');
        $parts[] = $this->setting('logo_corner') === 'none' ? 'no logo' : 'logo '.strtolower($o['logo_corner'][$this->setting('logo_corner')] ?? '');
        $parts[] = $this->setting('cta') === 'none' ? 'no button' : ($this->setting('cta') === 'button' ? 'a button' : 'plain call to action');

        return implode(' · ', $parts);
    }
}

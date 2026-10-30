<?php

namespace App\Models;

use App\Images\ImageRequest;
use App\Platforms\AspectRatio;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One image model the team can generate with: which plugin talks to it,
 * what it takes (shapes, sizes, qualities, reference images) and what it
 * costs. Admins switch models on and off and keep the prices current.
 */
#[Fillable(['provider', 'model', 'label', 'aspect_ratios', 'sizes', 'qualities', 'max_references', 'edits', 'transparent', 'renders_text',
    'prices', 'input_price', 'image_input_price', 'output_price', 'notes', 'enabled', 'position'])]
class ImageModel extends Model
{
    /** How far from the asked-for shape a model's closest shape may be before it's a different picture. */
    public const RATIO_TOLERANCE = 0.15;

    protected function casts(): array
    {
        return [
            'aspect_ratios' => 'array', 'sizes' => 'array', 'qualities' => 'array', 'prices' => 'array',
            'edits' => 'boolean', 'transparent' => 'boolean', 'renders_text' => 'boolean', 'enabled' => 'boolean',
            'input_price' => 'float', 'image_input_price' => 'float', 'output_price' => 'float',
        ];
    }

    /**
     * @return array<string, string> model key => label, for pickers.
     */
    public static function options(): array
    {
        return static::where('enabled', true)->orderBy('position')->orderBy('label')->get()
            ->mapWithKeys(fn (self $m) => [$m->key() => $m->label])->all();
    }

    /**
     * "gemini/gemini-nano-banana-2.1": unique across providers, for settings.
     */
    public function key(): string
    {
        return "{$this->provider}/{$this->model}";
    }

    public static function findByKey(?string $key): ?self
    {
        if (blank($key) || ! str_contains($key, '/')) {
            return null;
        }

        [$provider, $model] = explode('/', $key, 2);

        return static::where('provider', $provider)->where('model', $model)->first();
    }

    public function takesRatio(string $ratio): bool
    {
        return in_array($ratio, $this->aspect_ratios ?? [], true);
    }

    /**
     * The ratio to generate at for an asked-for one: itself when the model
     * takes it, else the closest it does take within RATIO_TOLERANCE (the
     * composer crops the rest), else null.
     */
    public function closestRatio(string $ratio): ?string
    {
        if ($this->takesRatio($ratio)) {
            return $ratio;
        }

        $wanted = AspectRatio::value($ratio);
        $best = collect($this->aspect_ratios ?? [])
            ->map(fn (string $label) => ['label' => $label, 'off' => abs(AspectRatio::value($label) - $wanted) / $wanted])
            ->sortBy('off')->first();

        return $best && $best['off'] <= self::RATIO_TOLERANCE ? $best['label'] : null;
    }

    public function takesSize(string $size): bool
    {
        return in_array($size, $this->sizes ?? [], true);
    }

    public function hasQualities(): bool
    {
        return ($this->qualities ?? []) !== [];
    }

    public function takesQuality(?string $quality): bool
    {
        return $quality === null || in_array($quality, $this->qualities ?? [], true);
    }

    /**
     * The price table's key for a request: "1K", or "medium 1K" on models with qualities.
     */
    public function priceKey(string $size, ?string $quality = null): string
    {
        return $this->hasQualities() && $quality ? "{$quality} {$size}" : $size;
    }

    /**
     * Estimated US dollars for one image, from the price table; null when
     * the table has no row for that size and quality.
     */
    public function price(string $size, ?string $quality = null): ?float
    {
        $price = $this->prices[$this->priceKey($size, $quality)] ?? null;

        return $price === null ? null : (float) $price;
    }

    /**
     * Estimated US dollars for a whole request (the middle quality when
     * none is named; ImageGenerator::estimate() asks the plugin instead).
     */
    public function estimate(ImageRequest $request): ?float
    {
        $price = $this->price($request->size, $request->quality ?? ($this->hasQualities() ? $this->qualities[intdiv(count($this->qualities) - 1, 2)] : null));

        return $price === null ? null : $price * max(1, $request->count);
    }

    /**
     * Actual US dollars from the provider's usage figures.
     */
    public function cost(int $textInput, int $imageInput, int $output): float
    {
        return ($textInput * $this->input_price + $imageInput * $this->image_input_price + $output * $this->output_price) / 1_000_000;
    }

    /**
     * What the model takes, in a few words.
     */
    public function summary(): string
    {
        $parts = [];
        $parts[] = count($this->aspect_ratios ?? []).' shapes';
        $parts[] = implode(', ', $this->sizes ?? []);
        if ($this->hasQualities()) {
            $parts[] = implode(', ', $this->qualities);
        }
        $parts[] = $this->max_references > 0 ? "up to {$this->max_references} reference images" : 'no reference images';
        if ($this->renders_text) {
            $parts[] = 'good text';
        }
        if ($this->transparent) {
            $parts[] = 'transparent backgrounds';
        }

        return implode(' · ', $parts);
    }
}

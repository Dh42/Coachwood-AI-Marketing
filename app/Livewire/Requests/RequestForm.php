<?php

namespace App\Livewire\Requests;

use App\Catalogue\Catalogue;
use App\Models\Brand;
use App\Models\CreativeRequest;
use App\Models\ImageModel;
use App\Models\PlacementPreset;
use App\Models\ProductVariant;
use App\Platforms\PlatformLibrary;
use App\Settings\Settings;
use Illuminate\Validation\ValidationException;

/**
 * The request form's fields and helpers, shared by the new-request page
 * and "Change the brief" on a request's page.
 */
trait RequestForm
{
    /** @var array<string, mixed> */
    public array $form = [];

    /** Words typed to find the product. */
    public string $productSearch = '';

    protected function blankForm(Brand $brand): array
    {
        return [
            'brief' => '', 'languages' => ['en'], 'output' => 'ad', 'ad_style' => 'composed', 'copy' => 'all',
            'placement_ids' => [], 'per_ratio' => '', 'deliver' => '', 'image_model' => '', 'product_variant_id' => '', 'public' => true,
        ];
    }

    protected function formFrom(CreativeRequest $request): array
    {
        return [
            'brief' => $request->brief, 'languages' => $request->languages ?? ['en'], 'output' => $request->output, 'ad_style' => $request->ad_style, 'copy' => $request->copy,
            'placement_ids' => array_map('strval', $request->placement_ids ?? []), 'per_ratio' => (string) ($request->per_ratio ?? ''), 'deliver' => (string) ($request->deliver ?? ''),
            'image_model' => (string) ($request->image_model ?? ''), 'product_variant_id' => (string) ($request->product_variant_id ?? ''), 'public' => $request->public,
        ];
    }

    public function tickSet(int $presetId): void
    {
        $preset = PlacementPreset::findOrFail($presetId);
        $this->form['placement_ids'] = array_values(array_unique([...$this->form['placement_ids'], ...$preset->placements()->map(fn ($p) => (string) $p->id)->all()]));
    }

    public function chooseVariant(int $variantId): void
    {
        $this->form['product_variant_id'] = (string) $variantId;
        $this->productSearch = '';
    }

    public function clearVariant(): void
    {
        $this->form['product_variant_id'] = '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(Brand $brand): array
    {
        $library = app(PlatformLibrary::class);
        $settings = app(Settings::class);
        $variantId = (int) ($this->form['product_variant_id'] ?? 0);

        return [
            'platforms' => $library->platforms(availableOnly: true),
            'sets' => $library->presets(),
            'models' => ImageModel::options(),
            'perRatioDefault' => (int) $settings->get('generation.per_ratio', $brand->id),
            'deliverDefault' => (int) $settings->get('review.deliver', $brand->id),
            'variant' => $variantId ? ProductVariant::with('product')->whereHas('product', fn ($q) => $q->where('brand_id', $brand->id))->find($variantId) : null,
            'matches' => mb_strlen(trim($this->productSearch)) >= 2 ? app(Catalogue::class)->search($brand, $this->productSearch, 6) : collect(),
        ];
    }

    /**
     * Prefixes the service's errors with "form." so they sit under the fields.
     */
    protected function formErrors(ValidationException $e): void
    {
        foreach ($e->errors() as $key => $messages) {
            foreach ($messages as $message) {
                $this->addError("form.{$key}", $message);
            }
        }
    }
}

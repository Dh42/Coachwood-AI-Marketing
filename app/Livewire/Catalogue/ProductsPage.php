<?php

namespace App\Livewire\Catalogue;

use App\Catalogue\Catalogue;
use App\Catalogue\Cutouts;
use App\Jobs\SyncCatalogue;
use App\Models\Brand;
use App\Models\CatalogueSync;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Settings\Settings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The brand's catalogue as the app sees it, with each variant's packaging
 * size, which the team types in (the store doesn't know it), and the
 * cut-outs of the store's photos for finished ads.
 */
#[Title('Products')]
class ProductsPage extends Component
{
    use WithPagination;

    #[Locked]
    public Brand $brand;

    #[Url]
    public string $search = '';

    /** all, live, missing-sizes, removed */
    #[Url]
    public string $show = 'all';

    /** The product open for editing sizes. */
    public ?int $open = null;

    /** @var array<int, array{width: string, height: string, depth: string}> Sizes being typed, by variant, in cm. */
    public array $sizes = [];

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'show'], true)) {
            $this->resetPage();
        }
    }

    public function syncNow(): void
    {
        $this->authorize('brands.manage', $this->brand);

        if (! app(Catalogue::class)->store($this->brand)) {
            $this->dispatch('failed', message: 'No store is chosen yet. Choose the brand\'s Shopify connection under Settings → Store.');

            return;
        }

        SyncCatalogue::dispatch($this->brand, 'manual', Auth::user());
        $this->dispatch('saved', message: 'Reading the store now. This page updates when it\'s done.');
    }

    /**
     * Queues a cut-out for every photo still without one (one product's,
     * or the whole brand's), including those that failed last time.
     */
    public function makeCutouts(Cutouts $cutouts, ?int $productId = null): void
    {
        $this->authorize('brands.manage', $this->brand);

        if (! $cutouts->remover($this->brand)) {
            $this->dispatch('failed', message: 'No background-removal service is connected yet. Add Photoroom under Connections, then choose it under Settings → Store if the brand has more than one.');

            return;
        }

        $count = $cutouts->queue($this->brand, Auth::user(), again: true, only: $productId ? $this->product($productId) : null);
        $count > 0
            ? $this->dispatch('saved', message: "Making {$count} ".Str::plural('cut-out', $count).'. This page updates as they land.')
            : $this->dispatch('failed', message: 'Every photo that an ad would use is cut out already.');
    }

    public function openProduct(int $id): void
    {
        $product = $this->product($id);
        $this->open = $product->id;
        $this->sizes = $product->variants->mapWithKeys(fn (ProductVariant $v) => [$v->id => [
            'width' => $v->width_mm === null ? '' : (string) ($v->width_mm / 10),
            'height' => $v->height_mm === null ? '' : (string) ($v->height_mm / 10),
            'depth' => $v->depth_mm === null ? '' : (string) ($v->depth_mm / 10),
        ]])->all();
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->open = null;
        $this->sizes = [];
        $this->resetErrorBag();
    }

    public function saveSizes(Catalogue $catalogue): void
    {
        $this->authorize('brands.manage', $this->brand);
        $product = $this->product($this->open ?? 0);
        $this->resetErrorBag();

        foreach ($product->variants as $variant) {
            try {
                $catalogue->saveDimensions($variant, $this->sizes[$variant->id] ?? ['width' => '', 'height' => '', 'depth' => ''], Auth::user());
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $this->addError("sizes.{$variant->id}.{$field}", $messages[0]);
                }
            }
        }

        if ($this->getErrorBag()->isEmpty()) {
            $this->dispatch('saved', message: "Saved the packaging sizes for {$product->title}.");
        }
    }

    /**
     * Gives every variant that shares this one's size option the same size.
     */
    public function copyToSameSize(int $variantId): void
    {
        $product = $this->product($this->open ?? 0);
        $from = $product->variants->firstWhere('id', $variantId) ?? abort(404);
        $sizeOption = collect($from->options)->first(fn ($value, $name) => in_array(mb_strtolower($name), ['size', 'taille', 'format', 'weight', 'poids'], true));

        foreach ($product->variants as $variant) {
            if ($variant->id !== $from->id && ($sizeOption === null || collect($variant->options)->contains($sizeOption))) {
                $this->sizes[$variant->id] = $this->sizes[$from->id];
            }
        }
    }

    public function render(Catalogue $catalogue, Cutouts $cutouts)
    {
        $search = trim($this->search);

        $products = Product::with(['variants.image', 'images'])
            ->where('brand_id', $this->brand->id)
            ->when($this->show !== 'removed', fn ($q) => $q->whereNull('removed_at'))
            ->when($this->show === 'removed', fn ($q) => $q->whereNotNull('removed_at'))
            ->when($this->show === 'live', fn ($q) => $q->where('status', 'active')->whereNotNull('url'))
            ->when($this->show === 'missing-sizes', fn ($q) => $q->whereHas('variants', fn ($v) => $v->whereNull('removed_at')->where(fn ($w) => $w->whereNull('width_mm')->orWhereNull('height_mm'))))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
                // JSON columns compare case-sensitively, so they're lowered first.
                $q->where(fn ($w) => $w->whereLike('title', $like)->orWhereLike('type', $like)->orWhereRaw('LOWER(tags) LIKE ?', [$like])->orWhereHas('variants', fn ($v) => $v->whereLike('sku', $like)->orWhereRaw('LOWER(options) LIKE ?', [$like])));
            })
            ->orderBy('title')
            ->paginate(25);

        $summary = $cutouts->summary($this->brand);

        return view('livewire.catalogue.products-page', [
            'products' => $products,
            'cutouts' => $cutouts,
            'remover' => $cutouts->remover($this->brand),
            'cutoutSummary' => $summary,
            'cutoutsPending' => $summary['wanted'] - $summary['made'] - $summary['failed'],
            'store' => $catalogue->store($this->brand),
            'lastSync' => $catalogue->lastSync($this->brand),
            'running' => $catalogue->lastSync($this->brand)?->status === CatalogueSync::RUNNING,
            'total' => Product::where('brand_id', $this->brand->id)->whereNull('removed_at')->count(),
            'missing' => ProductVariant::whereNull('removed_at')->whereHas('product', fn ($q) => $q->where('brand_id', $this->brand->id)->whereNull('removed_at'))->where(fn ($w) => $w->whereNull('width_mm')->orWhereNull('height_mm'))->count(),
            'canManage' => Auth::user()->can('brands.manage', $this->brand),
            'tz' => app(Settings::class)->get('platform.timezone'),
        ]);
    }

    private function product(int $id): Product
    {
        return Product::with(['variants.image', 'images'])->where('brand_id', $this->brand->id)->findOrFail($id);
    }
}

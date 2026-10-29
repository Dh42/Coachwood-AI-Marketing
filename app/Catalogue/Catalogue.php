<?php

namespace App\Catalogue;

use App\Models\Brand;
use App\Models\CatalogueSync;
use App\Models\Connection;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Plugins\Contracts\StoreProvider;
use App\Plugins\PluginRegistry;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * The brand's copy of its store's catalogue: read nightly and on demand
 * from the store connection chosen in the brand's Store settings, searched
 * by the planner, and holding the packaging sizes the team types in.
 */
class Catalogue
{
    /** The brand setting naming its store connection. */
    public const STORE_SETTING = 'store.connection';

    public function __construct(private PluginRegistry $plugins, private Settings $settings) {}

    /**
     * The brand's store connection, when one is chosen and its plugin is on.
     */
    public function store(Brand $brand): ?Connection
    {
        $id = (int) $this->settings->get(self::STORE_SETTING, $brand->id);
        $connection = $id ? Connection::find($id) : null;

        return $connection && $this->plugins->isEnabled($connection->plugin) && $connection->plugin() instanceof StoreProvider && $connection->isFor($brand) ? $connection : null;
    }

    /**
     * Brands that use this connection as their store.
     *
     * @return Collection<int, Brand>
     */
    public function brandsUsing(Connection $connection): Collection
    {
        $ids = Setting::where('key', self::STORE_SETTING)->where('scope', SettingScope::Brand->value)
            ->get()->filter(fn (Setting $s) => (string) $s->value === (string) $connection->id)->pluck('scope_id');

        return Brand::whereIn('id', $ids)->orderBy('name')->get();
    }

    /**
     * Reads the whole store and brings the copy up to date. Products gone
     * from the store are marked removed, never deleted.
     */
    public function sync(Brand $brand, string $source = 'schedule', ?User $by = null): CatalogueSync
    {
        $connection = $this->store($brand);
        $sync = CatalogueSync::create([
            'brand_id' => $brand->id, 'connection_id' => $connection?->id, 'source' => $source,
            'status' => CatalogueSync::RUNNING, 'started_at' => now(), 'user_id' => $by?->id,
        ]);

        try {
            if (! $connection) {
                throw new RuntimeException("No store is chosen for {$brand->name}. Choose its Shopify connection under Settings → Store.");
            }

            $counts = $this->read($brand, $connection);
            $sync->update([...$counts, 'status' => CatalogueSync::OK, 'finished_at' => now()]);
        } catch (Throwable $e) {
            report($e);
            $sync->update(['status' => CatalogueSync::FAILED, 'finished_at' => now(), 'error' => $e->getMessage()]);
        }

        activity('catalogue')->causedBy($by)->performedOn($brand)->event($sync->status === CatalogueSync::OK ? 'synced' : 'sync_failed')
            ->withProperties(['source' => $source, 'products' => $sync->products, 'added' => $sync->added, 'changed' => $sync->changed, 'removed' => $sync->removed, 'error' => $sync->error])
            ->log($sync->status === CatalogueSync::OK
                ? "Read {$brand->name}'s store: {$sync->products} products, {$sync->added} new, {$sync->changed} changed, {$sync->removed} removed"
                : "Couldn't read {$brand->name}'s store: {$sync->error}");

        return $sync;
    }

    /**
     * @return array{products: int, added: int, changed: int, removed: int}
     */
    private function read(Brand $brand, Connection $connection): array
    {
        /** @var StoreProvider $provider */
        $provider = $connection->plugin();
        $seen = [];
        $added = $changed = 0;
        $now = now();

        foreach ($provider->catalogue($connection) as $data) {
            $seen[] = $data['external_id'];

            DB::transaction(function () use ($brand, $connection, $data, $now, &$added, &$changed) {
                $product = Product::firstOrNew(['brand_id' => $brand->id, 'external_id' => $data['external_id']]);
                $new = ! $product->exists;

                $product->fill([
                    'connection_id' => $connection->id,
                    'handle' => $data['handle'] ?? null,
                    'title' => $data['title'],
                    'type' => $data['type'] ?? null,
                    'vendor' => $data['vendor'] ?? null,
                    'status' => $data['status'] ?? 'active',
                    'url' => $data['url'] ?? null,
                    'description_html' => $data['description_html'] ?? null,
                    'tags' => array_values($data['tags'] ?? []),
                    'options' => $data['options'] ?? [],
                    'details' => $data['details'] ?? [],
                    'removed_at' => null,
                ]);
                $dirty = $product->isDirty();
                $product->synced_at = $now;
                $product->save();

                $dirty = $this->syncImages($product, $data['images'] ?? []) || $dirty;
                $dirty = $this->syncVariants($product, $data['variants'] ?? []) || $dirty;

                if ($new) {
                    $added++;
                } elseif ($dirty) {
                    $changed++;
                }
            });
        }

        $removed = Product::where('brand_id', $brand->id)->whereNull('removed_at')->whereNotIn('external_id', $seen)->update(['removed_at' => $now]);

        return ['products' => count($seen), 'added' => $added, 'changed' => $changed, 'removed' => $removed];
    }

    /**
     * @param  list<array<string, mixed>>  $images
     */
    private function syncImages(Product $product, array $images): bool
    {
        $dirty = false;
        $seen = [];

        foreach ($images as $i => $data) {
            $seen[] = $data['external_id'];
            $image = ProductImage::firstOrNew(['product_id' => $product->id, 'external_id' => $data['external_id']]);
            $image->fill(['url' => $data['url'], 'alt' => $data['alt'] ?? null, 'position' => $data['position'] ?? $i, 'width' => $data['width'] ?? null, 'height' => $data['height'] ?? null, 'removed_at' => null]);
            // A new address means a new photo: the cut-out made from the old one no longer matches.
            if ($image->exists && $image->isDirty('url')) {
                $image->cutout_path = null;
            }
            $dirty = $dirty || ! $image->exists || $image->isDirty();
            $image->save();
        }

        $dirty = ProductImage::where('product_id', $product->id)->whereNull('removed_at')->whereNotIn('external_id', $seen)->update(['removed_at' => now()]) > 0 || $dirty;

        return $dirty;
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     */
    private function syncVariants(Product $product, array $variants): bool
    {
        $dirty = false;
        $seen = [];
        $images = ProductImage::where('product_id', $product->id)->pluck('id', 'external_id');

        foreach ($variants as $i => $data) {
            $seen[] = $data['external_id'];
            $variant = ProductVariant::firstOrNew(['product_id' => $product->id, 'external_id' => $data['external_id']]);
            $variant->fill([
                'title' => $data['title'], 'sku' => $data['sku'] ?? null, 'price' => $data['price'] ?? null, 'currency' => $data['currency'] ?? null,
                'available' => (bool) ($data['available'] ?? true), 'options' => $data['options'] ?? [], 'position' => $data['position'] ?? $i,
                'product_image_id' => isset($data['image_external_id']) ? $images->get($data['image_external_id']) : null,
                'removed_at' => null,
            ]);
            $dirty = $dirty || ! $variant->exists || $variant->isDirty();
            $variant->save();
        }

        $dirty = ProductVariant::where('product_id', $product->id)->whereNull('removed_at')->whereNotIn('external_id', $seen)->update(['removed_at' => now()]) > 0 || $dirty;

        return $dirty;
    }

    /**
     * Saves a variant's real packaging size, typed in centimetres.
     *
     * @param  array{width: mixed, height: mixed, depth: mixed}  $cm
     *
     * @throws ValidationException
     */
    public function saveDimensions(ProductVariant $variant, array $cm, User $by): void
    {
        $data = Validator::make($cm, [
            'width' => ['nullable', 'numeric', 'min:0.1', 'max:300'],
            'height' => ['nullable', 'numeric', 'min:0.1', 'max:300'],
            'depth' => ['nullable', 'numeric', 'min:0.1', 'max:300'],
        ], [], ['width' => 'width', 'height' => 'height', 'depth' => 'depth'])->validate();

        $mm = fn ($v) => $v === null || $v === '' ? null : (int) round(((float) $v) * 10);
        $old = $variant->only(['width_mm', 'height_mm', 'depth_mm']);

        $variant->update(['width_mm' => $mm($data['width'] ?? null), 'height_mm' => $mm($data['height'] ?? null), 'depth_mm' => $mm($data['depth'] ?? null), 'dimensions_by' => $by->id]);

        if ($variant->wasChanged()) {
            activity('catalogue')->causedBy($by)->performedOn($variant)->event('dimensions')
                ->withProperties(['old' => $old, 'new' => $variant->only(['width_mm', 'height_mm', 'depth_mm'])])
                ->log("Set the packaging size of {$variant->product->title} ({$variant->label()})");
        }
    }

    /**
     * Products in the brand's catalogue matching some words from a brief,
     * by title, handle, type, tags and variant options. Live products first.
     *
     * @return Collection<int, Product>
     */
    public function search(Brand $brand, string $text, int $limit = 10): Collection
    {
        $words = collect(preg_split('/[^\p{L}\p{N}%]+/u', mb_strtolower($text)))->filter(fn ($w) => mb_strlen($w) >= 2)->unique()->values();

        if ($words->isEmpty()) {
            return new Collection;
        }

        return Product::with(['variants' => fn ($q) => $q->whereNull('removed_at'), 'images' => fn ($q) => $q->whereNull('removed_at')])
            ->where('brand_id', $brand->id)->current()
            ->where(function ($query) use ($words) {
                foreach ($words as $word) {
                    $like = '%'.addcslashes($word, '%_\\').'%';
                    // JSON columns compare case-sensitively, so they're lowered first.
                    $query->orWhereLike('title', $like)->orWhereLike('handle', $like)->orWhereLike('type', $like)->orWhereRaw('LOWER(tags) LIKE ?', [$like])
                        ->orWhereHas('variants', fn ($q) => $q->whereNull('removed_at')->whereRaw('LOWER(options) LIKE ?', [$like]));
                }
            })
            ->get()
            ->sortByDesc(fn (Product $p) => [$p->isLive(), $words->filter(fn ($w) => str_contains(mb_strtolower($p->title), $w))->count()])
            ->take($limit)
            ->values();
    }

    /**
     * Brands whose store should be read now: a store is chosen, and today's
     * sync time (in the platform's time zone) has passed with no sync today.
     *
     * @return Collection<int, Brand>
     */
    public function due(): Collection
    {
        $tz = (string) $this->settings->get('platform.timezone');
        $now = now($tz);

        return Brand::orderBy('name')->get()->filter(function (Brand $brand) use ($now) {
            if (! $this->store($brand)) {
                return false;
            }

            [$hour, $minute] = array_map('intval', explode(':', (string) $this->settings->get('catalogue.sync_time', $brand->id)));
            $at = $now->copy()->setTime($hour, $minute);

            return $now->greaterThanOrEqualTo($at)
                && ! CatalogueSync::where('brand_id', $brand->id)->where('started_at', '>=', $at->copy()->timezone('UTC'))->exists();
        })->values();
    }

    public function lastSync(Brand $brand): ?CatalogueSync
    {
        return CatalogueSync::where('brand_id', $brand->id)->latest('id')->first();
    }
}

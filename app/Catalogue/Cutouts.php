<?php

namespace App\Catalogue;

use App\BrandKit\KitFiles;
use App\Budgets\Budgets;
use App\Jobs\MakeCutout;
use App\Models\Brand;
use App\Models\Connection;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Plugins\Connections;
use App\Plugins\Contracts\BackgroundRemover;
use App\Plugins\IntegrationPlugin;
use App\Plugins\PluginRegistry;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Cut-outs of the store's product photos: the background removed once per
 * photo by a background-removal plugin and kept on the disk, so finished
 * ads and the reviewer have a real pack shot for a variant when the brand
 * kit has no transparent one. Only the photos an ad would use are cut out:
 * each current variant's own photo, or the product's first.
 */
class Cutouts
{
    /** The brand setting naming the connection that cuts its photos out (empty: any working one). */
    public const SETTING = 'cutouts.connection';

    /** The kind of spend a cut-out is recorded as. */
    public const KIND = 'cutout';

    public function __construct(private PluginRegistry $plugins, private Connections $connections, private Settings $settings, private Budgets $budgets) {}

    public function disk(): Filesystem
    {
        return Storage::disk();
    }

    /**
     * The connection that cuts this brand's photos out: the one chosen in
     * its Store settings, else any the brand may use, working ones first.
     */
    public function remover(Brand $brand): ?Connection
    {
        $id = (int) $this->settings->get(self::SETTING, $brand->id);
        if ($id) {
            $chosen = Connection::find($id);

            return $chosen && $this->usable($chosen, $brand) ? $chosen : null;
        }

        return $this->plugins->providing(BackgroundRemover::class)->keys()
            ->map(fn (string $key) => $this->connections->for($key, $brand))
            ->filter()
            ->sortByDesc(fn (Connection $c) => $c->isConnected())
            ->first();
    }

    /**
     * Brands whose Store settings name this connection for cut-outs.
     *
     * @return Collection<int, Brand>
     */
    public function brandsUsing(Connection $connection): Collection
    {
        $ids = Setting::where('key', self::SETTING)->where('scope', SettingScope::Brand->value)
            ->get()->filter(fn (Setting $s) => (string) $s->value === (string) $connection->id)->pluck('scope_id');

        return Brand::whereIn('id', $ids)->orderBy('name')->get();
    }

    /**
     * The store photo an ad of this variant would use: its own, else the
     * product's first. Null when the store gave the product no photo.
     */
    public function imageFor(ProductVariant $variant): ?ProductImage
    {
        $own = $variant->image;
        if ($own && $own->removed_at === null) {
            return $own;
        }

        return $variant->product->images->first(fn (ProductImage $i) => $i->removed_at === null);
    }

    /**
     * The path on the disk of the variant's cut-out, when it's been made.
     */
    public function pathFor(ProductVariant $variant): ?string
    {
        return $this->imageFor($variant)?->cutout_path;
    }

    /**
     * The photos of a product that want a cut-out: one per current variant.
     *
     * @return BaseCollection<int, ProductImage>
     */
    public function wanted(Product $product): BaseCollection
    {
        $product->loadMissing(['images', 'variants.image']);

        return $product->variants->filter(fn (ProductVariant $v) => $v->removed_at === null)
            ->map(fn (ProductVariant $v) => $this->imageFor($v))->filter()->unique('id')->values()->toBase();
    }

    /**
     * How a product's cut-outs stand: wanted, made, and failed last time.
     *
     * @return array{wanted: int, made: int, failed: int}
     */
    public function status(Product $product): array
    {
        $wanted = $this->wanted($product);

        return ['wanted' => $wanted->count(), 'made' => $wanted->filter->hasCutout()->count(), 'failed' => $wanted->filter(fn (ProductImage $i) => ! $i->hasCutout() && $i->cutout_error !== null)->count()];
    }

    /**
     * The same across the brand's current products.
     *
     * @return array{wanted: int, made: int, failed: int}
     */
    public function summary(Brand $brand): array
    {
        $totals = ['wanted' => 0, 'made' => 0, 'failed' => 0];
        foreach (Product::with(['images', 'variants.image'])->where('brand_id', $brand->id)->current()->get() as $product) {
            foreach ($this->status($product) as $k => $n) {
                $totals[$k] += $n;
            }
        }

        return $totals;
    }

    /**
     * The brand's photos still to cut out, leaving aside those that failed
     * last time unless asked for them again.
     *
     * @return BaseCollection<int, ProductImage>
     */
    public function due(Brand $brand, bool $again = false, ?Product $only = null): BaseCollection
    {
        $products = $only ? collect([$only]) : Product::with(['images', 'variants.image'])->where('brand_id', $brand->id)->current()->get();

        return $products->toBase()->flatMap(fn (Product $p) => $this->wanted($p))
            ->filter(fn (ProductImage $i) => ! $i->hasCutout() && ($again || $i->cutout_error === null))
            ->unique('id')->values();
    }

    /**
     * Queues a cut-out for each photo due, and says how many. Nothing is
     * queued when the brand has no background-removal connection.
     */
    public function queue(Brand $brand, ?User $by = null, bool $again = false, ?Product $only = null): int
    {
        if (! $this->remover($brand)) {
            return 0;
        }

        $due = $this->due($brand, $again, $only);
        foreach ($due as $image) {
            MakeCutout::dispatch($image, $by);
        }

        return $due->count();
    }

    /**
     * Cuts one photo out now: fetches it from the store, sends it to the
     * remover, checks the result is see-through, keeps it on the disk and
     * records the spend. A failure is written on the photo and rethrown.
     *
     * @throws CutoutException
     */
    public function make(ProductImage $image, ?User $by = null): ProductImage
    {
        $product = $image->product;
        $brand = $product->brand;

        try {
            $connection = $this->remover($brand) ?? throw new CutoutException("No background-removal service is connected for {$brand->name}. Add one under Connections (Photoroom), or upload a transparent pack shot to the brand kit.");
            /** @var BackgroundRemover&IntegrationPlugin $plugin */
            $plugin = $connection->plugin();
            if (! $connection->isConnected()) {
                throw new CutoutException("The {$plugin->name()} connection \"{$connection->name}\" isn't working: ".($connection->last_error ?: 'it hasn\'t been checked yet').'. Open it under Connections and press "Check now".');
            }

            [$bytes, $mime] = $this->fetch($image);
            $cutout = $plugin->removeBackground($connection, $bytes, $mime);
            $this->verify($cutout->png, $plugin->name());

            $path = "catalogue/{$brand->id}/cutouts/{$image->id}.png";
            $this->disk()->put($path, $cutout->png);
            $image->forceFill(['cutout_path' => $path, 'cutout_error' => null])->save();
        } catch (Throwable $e) {
            $image->forceFill(['cutout_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            activity('catalogue')->causedBy($by)->performedOn($product)->event('cutout_failed')->withProperties(['image' => $image->id, 'error' => $e->getMessage()])
                ->log("Couldn't cut {$product->title} out of its store photo: {$e->getMessage()}");

            throw $e instanceof CutoutException ? $e : new CutoutException($e->getMessage(), retryable: $e instanceof ConnectionException);
        }

        if ($cutout->cost > 0) {
            $this->budgets->record($connection->plugin, self::KIND, $cutout->cost, $brand, $by, note: $product->title);
        }
        activity('catalogue')->causedBy($by)->performedOn($product)->event('cutout_made')->withProperties(['image' => $image->id, 'cost' => $cutout->cost, 'provider' => $connection->plugin])
            ->log("Cut {$product->title} out of its store photo");

        return $image;
    }

    /**
     * The photo's bytes and type from the store's CDN.
     *
     * @return array{0: string, 1: string}
     */
    private function fetch(ProductImage $image): array
    {
        try {
            $response = Http::timeout(60)->get($image->url);
        } catch (ConnectionException) {
            throw new CutoutException('Couldn\'t fetch the photo from the store. It can be tried again shortly.', retryable: true);
        }

        if ($response->failed()) {
            throw new CutoutException("The store wouldn't give the photo ({$response->status()}).", retryable: $response->serverError());
        }

        $bytes = $response->body();
        $mime = @getimagesizefromstring($bytes)['mime'] ?? null;
        if (! $mime) {
            throw new CutoutException('What the store sent isn\'t a picture.');
        }

        return [$bytes, $mime];
    }

    /**
     * A cut-out must decode and have see-through edges, or it's no better than the photo.
     */
    private function verify(string $png, string $service): void
    {
        $gd = @imagecreatefromstring($png);
        if (! $gd) {
            throw new CutoutException("{$service} sent back something that isn't a picture.");
        }
        if (! KitFiles::hasTransparentEdge($gd)) {
            throw new CutoutException("{$service} sent back a picture without a see-through background, so it wasn't kept.");
        }
    }

    private function usable(Connection $connection, Brand $brand): bool
    {
        return $this->plugins->isEnabled($connection->plugin) && $connection->plugin() instanceof BackgroundRemover && $connection->isFor($brand);
    }
}

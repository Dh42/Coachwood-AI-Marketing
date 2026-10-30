<?php

use App\Auth\Role;
use App\Catalogue\Catalogue;
use App\Catalogue\CutoutException;
use App\Catalogue\Cutouts;
use App\Jobs\MakeCutout;
use App\Jobs\SyncCatalogue;
use App\Livewire\Catalogue\ProductsPage;
use App\Models\Connection;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Spend;
use App\Plugins\Connections;
use App\Plugins\PluginRegistry;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeRemover;
use Tests\Support\FakeStore;

beforeEach(function () {
    Storage::fake();
    $this->store = new FakeStore;
    $this->remover = new FakeRemover;
    app(PluginRegistry::class)->register($this->store);
    app(PluginRegistry::class)->register($this->remover);
    $this->admin = signInAs(Role::Admin);
    $this->brand = makeBrand(['name' => 'BioSteel']);
    $this->storeConnection = Connection::factory()->create(['plugin' => 'store', 'name' => 'BioSteel store', 'brand_id' => $this->brand->id]);
    app(Settings::class)->set(Catalogue::STORE_SETTING, $this->storeConnection->id, SettingScope::Brand, $this->brand->id, $this->admin);
    $this->cutouts = app(Cutouts::class);
    // The store's photos: a JPEG with a white background, as Shopify serves them.
    $im = imagecreatetruecolor(30, 30);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    ob_start();
    imagejpeg($im);
    Http::fake(['cdn.example/*' => Http::response((string) ob_get_clean(), 200, ['Content-Type' => 'image/jpeg'])]);
});

function connectRemover(?int $brandId = null, string $name = 'Photoroom'): Connection
{
    return Connection::factory()->create(['plugin' => 'remover', 'name' => $name, 'brand_id' => $brandId]);
}

it('cuts out the photo each variant would use once the store is read, keeps it, records the spend and shows it on Products', function () {
    connectRemover();
    // Whey: two variants on one photo. Shaker: one variant, a photo. Bars: a photo no variant points at, and one they share.
    $this->store->products = [
        FakeStore::product('p1', 'Whey', [['v1', ['Flavour' => 'Vanilla']], ['v2', ['Flavour' => 'Chocolate']]]),
        FakeStore::product('p2', 'Shaker'),
        FakeStore::product('p3', 'Bars', [['v4', []]], ['images' => [
            ['external_id' => 'p3-img', 'url' => 'https://cdn.example/p3.png', 'alt' => null, 'position' => 0, 'width' => 800, 'height' => 800],
            ['external_id' => 'p3-box', 'url' => 'https://cdn.example/p3-box.png', 'alt' => null, 'position' => 1, 'width' => 800, 'height' => 800],
        ]]),
    ];

    (new SyncCatalogue($this->brand, 'manual', $this->admin))->handle(app(Catalogue::class), $this->cutouts);

    $whey = ProductImage::where('external_id', 'p1-img')->sole();
    expect($this->remover->calls)->toHaveCount(3)->and($this->remover->calls[0]['mime'])->toBe('image/jpeg')
        ->and($whey->cutout_path)->toBe("catalogue/{$this->brand->id}/cutouts/{$whey->id}.png")->and(Storage::disk()->get($whey->cutout_path))->toBe(FakeRemover::transparentPng())
        ->and(ProductImage::where('external_id', 'p3-box')->sole()->cutout_path)->toBeNull()
        ->and(Spend::where('kind', 'cutout')->where('provider', 'remover')->count())->toBe(3)->and((float) Spend::where('note', 'Whey')->sole()->amount)->toBe(0.03)
        ->and(Activity::where('event', 'cutout_made')->count())->toBe(3)->and(Activity::where('event', 'cutout_made')->first()->description)->toBe('Cut Whey out of its store photo')
        ->and($this->cutouts->pathFor(ProductVariant::where('external_id', 'v2')->sole()))->toBe($whey->cutout_path)
        ->and($this->cutouts->summary($this->brand))->toBe(['wanted' => 3, 'made' => 3, 'failed' => 0]);

    // Reading the store again makes nothing again; a photo that changed is made afresh.
    $this->remover->calls = [];
    $this->store->products[1]['images'][0]['url'] = 'https://cdn.example/p2-new.png';
    (new SyncCatalogue($this->brand))->handle(app(Catalogue::class), $this->cutouts);
    expect($this->remover->calls)->toHaveCount(1)->and(ProductImage::where('external_id', 'p2-img')->sole()->cutout_path)->not->toBeNull();

    $this->get(route('products', $this->brand))->assertOk()->assertSee('3 of 3 store photos are cut out for finished ads')->assertSee('All set')->assertDontSee('Make the cut-outs');
    Livewire::test(ProductsPage::class, ['brand' => $this->brand])->call('openProduct', Product::where('title', 'Bars')->sole()->id)
        ->assertSee('Cut out')->assertSee('Not used by any variant')->assertSee(route('products.cutout', [$this->brand, ProductImage::where('external_id', 'p3-img')->sole()]), false);
    $this->get(route('products.cutout', [$this->brand, $whey]))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get(route('products.cutout', [makeBrand(), $whey]))->assertNotFound();
});

it('writes a failure on the photo, refuses a result that isn\'t see-through, and tries a failed one again only when asked', function () {
    connectRemover();
    $this->store->products = [FakeStore::product('p1', 'Whey')];
    app(Catalogue::class)->sync($this->brand);
    $image = ProductImage::sole();

    // A flat picture is no better than the photo.
    $this->remover->png = FakeRemover::transparentPng(transparent: false);
    expect(fn () => $this->cutouts->make($image, $this->admin))->toThrow(CutoutException::class, 'Remover sent back a picture without a see-through background, so it wasn\'t kept.');
    expect($image->fresh()->cutout_error)->toContain('see-through')->and($image->fresh()->cutout_path)->toBeNull()
        ->and(Spend::count())->toBe(0)->and(Activity::where('event', 'cutout_failed')->sole()->description)->toStartWith('Couldn\'t cut Whey out of its store photo:')
        ->and($this->cutouts->due($this->brand))->toHaveCount(0)->and($this->cutouts->due($this->brand, again: true))->toHaveCount(1)
        ->and($this->cutouts->queue($this->brand))->toBe(0);

    $this->get(route('products', $this->brand))->assertSee('1 couldn\'t be: open the product to see why.', false)->assertSee('1 failed');
    $page = Livewire::test(ProductsPage::class, ['brand' => $this->brand])->call('openProduct', Product::sole()->id)
        ->assertSee('Couldn\'t be cut out: Remover sent back a picture without a see-through background', false)->assertSee('Try the cut-outs again');

    // Once the service behaves, asking again makes it.
    $this->remover->png = null;
    $page->call('makeCutouts', Product::sole()->id)->assertDispatched('saved', message: 'Making 1 cut-out. This page updates as they land.');
    expect($image->fresh()->cutout_path)->not->toBeNull()->and($image->fresh()->cutout_error)->toBeNull();
    $page->call('makeCutouts')->assertDispatched('failed', message: 'Every photo that an ad would use is cut out already.');

    // The store's CDN being down is worth another go (the job tries again); what isn't a picture is not.
    Http::fake(['down.example/*' => Http::response('', 503), 'junk.example/*' => Http::response('x', 200)]);
    $image->forceFill(['cutout_path' => null, 'url' => 'https://down.example/p1.png'])->save();
    try {
        $this->cutouts->make($image);
        $this->fail('Made a cut-out from nothing.');
    } catch (CutoutException $e) {
        expect($e->getMessage())->toBe('The store wouldn\'t give the photo (503).')->and($e->retryable)->toBeTrue()->and($image->fresh()->cutout_error)->toContain('503');
    }
    $image->forceFill(['url' => 'https://junk.example/p1.png'])->save();
    expect(fn () => $this->cutouts->make($image))->toThrow(CutoutException::class, 'What the store sent isn\'t a picture.');

    signInAs(Role::Viewer);
    Livewire::test(ProductsPage::class, ['brand' => $this->brand])->call('makeCutouts')->assertForbidden();
});

it('uses the connection chosen in the Store settings, else any working one the brand may use, and says which brands use one', function () {
    $this->store->products = [FakeStore::product('p1', 'Whey')];
    app(Catalogue::class)->sync($this->brand);

    // Nothing connected: nothing is queued, and the page says so.
    expect($this->cutouts->remover($this->brand))->toBeNull()->and($this->cutouts->queue($this->brand, $this->admin))->toBe(0);
    $this->get(route('products', $this->brand))->assertSee('No background-removal service is connected')->assertSee('1 to make');
    Livewire::test(ProductsPage::class, ['brand' => $this->brand])->call('makeCutouts')->assertDispatched('failed');
    expect(fn () => $this->cutouts->make(ProductImage::sole()))->toThrow(CutoutException::class, 'No background-removal service is connected for BioSteel.');

    // Someone else's brand's connection doesn't count; a shared one does, a working one before a broken one.
    connectRemover(makeBrand()->id, 'Elsewhere');
    expect($this->cutouts->remover($this->brand))->toBeNull();
    $broken = Connection::factory()->create(['plugin' => 'remover', 'name' => 'Broken', 'status' => Connection::STATUS_ERROR, 'last_error' => 'Refused']);
    expect($this->cutouts->remover($this->brand)?->id)->toBe($broken->id);
    $shared = connectRemover(null, 'Shared');
    expect($this->cutouts->remover($this->brand)?->id)->toBe($shared->id);

    // The brand's own choice wins, is offered on its Store settings, and holds the connection in use.
    $own = connectRemover($this->brand->id, 'Own');
    app(Settings::class)->set(Cutouts::SETTING, $broken->id, SettingScope::Brand, $this->brand->id, $this->admin);
    expect($this->cutouts->remover($this->brand)?->id)->toBe($broken->id)
        ->and(app(Connections::class)->usesOf($broken))->toBe(["BioSteel's cut-outs"])->and(app(Connections::class)->usesOf($own))->toBe([]);
    expect(fn () => app(Connections::class)->delete($broken, $this->admin))->toThrow(ValidationException::class);
    $this->get(route('area-settings', [$this->brand, 'store']))->assertOk()->assertSee('Cut-outs by')->assertSee('Own')->assertSee('Shared')->assertSee('Broken')->assertDontSee('Elsewhere');

    // A broken connection is named when a cut-out is tried on it.
    expect(fn () => $this->cutouts->make(ProductImage::sole()))->toThrow(CutoutException::class, 'The Remover connection "Broken" isn\'t working: Refused.');

    // The page's button queues one job per photo due.
    app(Settings::class)->set(Cutouts::SETTING, $own->id, SettingScope::Brand, $this->brand->id, $this->admin);
    Queue::fake();
    Livewire::test(ProductsPage::class, ['brand' => $this->brand])->call('makeCutouts')->assertDispatched('saved');
    Queue::assertPushed(MakeCutout::class, fn (MakeCutout $job) => $job->image->is(ProductImage::sole()) && $job->by?->is($this->admin));
});

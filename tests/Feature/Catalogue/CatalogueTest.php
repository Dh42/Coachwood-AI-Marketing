<?php

use App\Auth\Role;
use App\Catalogue\Catalogue;
use App\Jobs\SyncCatalogue;
use App\Livewire\Catalogue\ProductsPage;
use App\Models\CatalogueSync;
use App\Models\Connection;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Plugins\Connections;
use App\Plugins\PluginRegistry;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeStore;

beforeEach(function () {
    $this->store = new FakeStore;
    app(PluginRegistry::class)->register($this->store);
    $this->admin = signInAs(Role::Admin);
    $this->brand = makeBrand(['name' => 'BioSteel']);
    $this->connection = Connection::factory()->create(['plugin' => 'store', 'name' => 'BioSteel store', 'brand_id' => $this->brand->id]);
    app(Settings::class)->set(Catalogue::STORE_SETTING, $this->connection->id, SettingScope::Brand, $this->brand->id, $this->admin);
    $this->catalogue = app(Catalogue::class);
});

it('copies the store, then updates, removes and brings back products on later reads', function () {
    $this->store->products = [FakeStore::product('p1', 'Hydration Mix', [['v1', ['Size' => '45 serve', 'Flavour' => 'Blue Raspberry']], ['v2', ['Size' => '45 serve', 'Flavour' => 'Mixed Berry']]]), FakeStore::product('p2', 'Shaker')];

    $first = $this->catalogue->sync($this->brand, 'manual', $this->admin);
    expect($first)->status->toBe(CatalogueSync::OK)->products->toBe(2)->added->toBe(2)->changed->toBe(0)->removed->toBe(0)
        ->and(Product::count())->toBe(2)->and(ProductVariant::count())->toBe(3)
        ->and(Product::where('handle', 'hydration-mix')->sole()->options)->toBe(['Size' => ['45 serve'], 'Flavour' => ['Blue Raspberry', 'Mixed Berry']])
        ->and(Activity::where('log_name', 'catalogue')->sole()->description)->toBe("Read BioSteel's store: 2 products, 2 new, 0 changed, 0 removed");

    // Same again: nothing changed.
    expect($this->catalogue->sync($this->brand))->changed->toBe(0)->added->toBe(0);

    // A rename, a variant gone, a product gone.
    $this->store->products = [FakeStore::product('p1', 'Hydration Mix 2.0', [['v1', ['Size' => '45 serve', 'Flavour' => 'Blue Raspberry']]])];
    $second = $this->catalogue->sync($this->brand);
    expect($second)->changed->toBe(1)->removed->toBe(1)
        ->and(Product::where('external_id', 'p2')->sole()->removed_at)->not->toBeNull()
        ->and(ProductVariant::where('external_id', 'v2')->sole()->removed_at)->not->toBeNull()
        ->and(Product::current()->count())->toBe(1);

    // Back on the store: current again, same row.
    $this->store->products[] = FakeStore::product('p2', 'Shaker');
    $this->catalogue->sync($this->brand);
    expect(Product::where('external_id', 'p2')->sole()->removed_at)->toBeNull()->and(Product::count())->toBe(2);
});

it('records a failed read with the reason, and when no store is chosen', function () {
    $this->store->failure = new RuntimeException('Store exploded');
    expect($this->catalogue->sync($this->brand))->status->toBe(CatalogueSync::FAILED)->error->toBe('Store exploded');

    $other = makeBrand();
    expect($this->catalogue->sync($other))->status->toBe(CatalogueSync::FAILED)->error->toContain('No store is chosen');
});

it('keeps packaging sizes through a read, in millimetres from centimetres, and forgets a cut-out when the photo changes', function () {
    $this->store->products = [FakeStore::product('p1', 'Whey', [['v1', ['Size' => '1 kg']], ['v2', ['Size' => '2 kg']]])];
    $this->catalogue->sync($this->brand);
    $variant = ProductVariant::where('external_id', 'v1')->sole();

    $this->catalogue->saveDimensions($variant, ['width' => '15.5', 'height' => '24', 'depth' => ''], $this->admin);
    expect($variant->fresh())->width_mm->toBe(155)->height_mm->toBe(240)->depth_mm->toBeNull()->dimensions_by->toBe($this->admin->id)
        ->and($variant->fresh()->hasDimensions())->toBeTrue()
        ->and(Activity::where('event', 'dimensions')->sole()->description)->toBe('Set the packaging size of Whey (1 kg)');

    $image = Product::sole()->images->sole();
    $image->update(['cutout_path' => 'cutouts/1.png']);

    $this->store->products[0]['images'][0]['url'] = 'https://cdn.example/new.png';
    $this->catalogue->sync($this->brand);
    expect($variant->fresh()->width_mm)->toBe(155)->and($image->fresh()->cutout_path)->toBeNull();
});

it('rejects a size that isn\'t a size', function () {
    $this->store->products = [FakeStore::product('p1', 'Whey')];
    $this->catalogue->sync($this->brand);

    $this->catalogue->saveDimensions(ProductVariant::sole(), ['width' => 'wide', 'height' => '10', 'depth' => null], $this->admin);
})->throws(ValidationException::class);

it('finds products from the words of a brief, live ones first', function () {
    $this->store->products = [
        FakeStore::product('p1', 'Whey Protein Isolate', [['v1', ['Size' => '1 kg', 'Flavour' => 'Vanilla']]]),
        FakeStore::product('p2', 'Whey Isolate Plus', [['v2', ['Size' => '2 kg', 'Flavour' => 'Chocolate']]], ['url' => null, 'status' => 'draft']),
        FakeStore::product('p3', 'Creatine', extra: ['type' => 'Supplement']),
    ];
    $this->catalogue->sync($this->brand);

    expect($this->catalogue->search($this->brand, 'a sale on whey protein isolate, 20% off')->pluck('title')->all())->toBe(['Whey Protein Isolate', 'Whey Isolate Plus'])
        ->and($this->catalogue->search($this->brand, 'chocolate')->pluck('title')->all())->toBe(['Whey Isolate Plus'])
        ->and($this->catalogue->search($this->brand, 'socks'))->toBeEmpty()
        ->and($this->catalogue->search(makeBrand(), 'whey'))->toBeEmpty();
});

it('reads each brand\'s store once a day at its own time', function () {
    Queue::fake();
    app(Settings::class)->set('platform.timezone', 'UTC', SettingScope::Platform, 0, $this->admin);
    app(Settings::class)->set('catalogue.sync_time', '03:00', SettingScope::Brand, $this->brand->id, $this->admin);
    makeBrand(['name' => 'No store']);

    $this->travelTo(now()->setTime(2, 30));
    expect($this->catalogue->due())->toBeEmpty();

    $this->travelTo(now()->setTime(3, 5));
    expect($this->catalogue->due()->pluck('name')->all())->toBe(['BioSteel']);
    $this->artisan('catalogue:sync-due')->expectsOutputToContain('Queued BioSteel.');
    Queue::assertPushed(SyncCatalogue::class, fn (SyncCatalogue $job) => $job->brand->is($this->brand) && $job->source === 'schedule');

    CatalogueSync::create(['brand_id' => $this->brand->id, 'source' => 'schedule', 'status' => 'ok', 'started_at' => now()]);
    expect($this->catalogue->due())->toBeEmpty();
});

it('shows the products page with sizes to fill in, and saves them for those who may', function () {
    $this->store->products = [FakeStore::product('p1', 'Whey', [['v1', ['Size' => '1 kg', 'Flavour' => 'Vanilla']], ['v2', ['Size' => '1 kg', 'Flavour' => 'Chocolate']], ['v3', ['Size' => '2 kg', 'Flavour' => 'Vanilla']]])];
    $this->catalogue->sync($this->brand);
    [$v1, $v2, $v3] = ProductVariant::orderBy('id')->get();

    $this->get(route('products', $this->brand))->assertOk()->assertSee('Whey')->assertSee('3 missing')->assertSee('Read from')->assertSee('BioSteel store');

    Livewire::test(ProductsPage::class, ['brand' => $this->brand])
        ->call('openProduct', Product::sole()->id)
        ->set("sizes.{$v1->id}.width", '12')->set("sizes.{$v1->id}.height", '20')
        ->call('copyToSameSize', $v1->id)
        ->assertSet("sizes.{$v2->id}.width", '12')->assertSet("sizes.{$v3->id}.width", '')
        ->call('saveSizes')->assertHasNoErrors()->assertDispatched('saved');

    expect($v1->fresh()->width_mm)->toBe(120)->and($v2->fresh()->height_mm)->toBe(200)->and($v3->fresh()->width_mm)->toBeNull();
    $this->get(route('products', $this->brand))->assertSee('1 missing');

    signInAs(Role::Viewer);
    $this->get(route('products', $this->brand))->assertOk()->assertDontSee('Read the store now');
    Livewire::test(ProductsPage::class, ['brand' => $this->brand])->call('openProduct', Product::sole()->id)->call('saveSizes')->assertForbidden();
});

it('reads the store now from the page, once at a time', function () {
    Queue::fake();
    Cache::flush();

    Livewire::test(ProductsPage::class, ['brand' => $this->brand])->call('syncNow')->assertDispatched('saved');
    Queue::assertPushed(SyncCatalogue::class, fn (SyncCatalogue $job) => $job->source === 'manual' && $job->by?->is($this->admin));

    $other = makeBrand();
    Livewire::test(ProductsPage::class, ['brand' => $other])->call('syncNow')->assertDispatched('failed');
});

it('says which brands use a store connection, so it can\'t be removed from under them', function () {
    expect(app(Connections::class)->usesOf($this->connection))->toBe(["BioSteel's store"]);

    expect(fn () => app(Connections::class)->delete($this->connection, $this->admin))->toThrow(ValidationException::class);
    $this->get(route('connections'))->assertSee("Used by BioSteel's store");
});

it('offers only store connections the brand may use in its Store settings', function () {
    $shared = Connection::factory()->create(['plugin' => 'store', 'name' => 'Shared store']);
    Connection::factory()->create(['plugin' => 'store', 'name' => 'Someone else\'s', 'brand_id' => makeBrand()->id]);
    Connection::factory()->create(['plugin' => 'fake', 'name' => 'Not a store']);

    $this->get(route('area-settings', [$this->brand, 'store']))->assertOk()
        ->assertSee('BioSteel store')->assertSee('Shared store')->assertDontSee('Someone else')->assertDontSee('Not a store')->assertSee('Read the store each day at');
});

<?php

use App\Auth\Role;
use App\Catalogue\Catalogue;
use App\Models\Connection;
use App\Models\Product;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Coachwood\Plugins\Shopify\ShopifyPlugin;
use Illuminate\Support\Facades\Http;

function shopifyPage(array $nodes, bool $more = false): array
{
    return ['data' => ['products' => ['pageInfo' => ['hasNextPage' => $more, 'endCursor' => $more ? 'next' : null], 'nodes' => $nodes]]];
}

function shopifyProduct(string $id, string $title, array $extra = []): array
{
    return [
        'id' => "gid://shopify/Product/{$id}", 'handle' => Str::slug($title), 'title' => $title, 'productType' => 'Protein', 'vendor' => 'CP', 'status' => 'ACTIVE',
        'tags' => ['whey', 'isolate'], 'onlineStoreUrl' => 'https://shop.example/products/'.Str::slug($title), 'descriptionHtml' => '<p>Good stuff.</p>',
        'options' => [['name' => 'Size', 'values' => ['1 kg', '2 kg']], ['name' => 'Flavour', 'values' => ['Vanilla']]],
        'images' => ['nodes' => [['id' => "gid://shopify/ProductImage/{$id}1", 'url' => 'https://cdn.example/a.png', 'altText' => 'Front', 'width' => 1000, 'height' => 1200]]],
        'variants' => ['nodes' => [
            ['id' => "gid://shopify/ProductVariant/{$id}1", 'title' => '1 kg / Vanilla', 'sku' => 'WPI-1', 'price' => '49.99', 'availableForSale' => true, 'position' => 1,
                'selectedOptions' => [['name' => 'Size', 'value' => '1 kg'], ['name' => 'Flavour', 'value' => 'Vanilla']], 'image' => ['id' => "gid://shopify/ProductImage/{$id}1"]],
            ['id' => "gid://shopify/ProductVariant/{$id}2", 'title' => '2 kg / Vanilla', 'sku' => 'WPI-2', 'price' => '89.99', 'availableForSale' => false, 'position' => 2,
                'selectedOptions' => [['name' => 'Size', 'value' => '2 kg'], ['name' => 'Flavour', 'value' => 'Vanilla']], 'image' => null],
        ]],
        'metafields' => ['nodes' => [
            ['namespace' => 'custom', 'key' => '[p][_t] 2b. nutritional_fact', 'type' => 'multi_line_text_field', 'value' => "Protein 27 g\nCarbs 1 g"],
            ['namespace' => 'custom', 'key' => 'benefits', 'type' => 'rich_text_field', 'value' => json_encode(['type' => 'root', 'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'Builds muscle']]]]])],
            ['namespace' => 'custom', 'key' => 'weight_g', 'type' => 'number_integer', 'value' => '1000'],
        ]],
        ...$extra,
    ];
}

beforeEach(function () {
    $this->admin = signInAs(Role::Admin);
    $this->brand = makeBrand(['name' => 'Canadian Protein']);
    $this->connection = Connection::factory()->create(['plugin' => 'shopify', 'name' => 'CP Shopify', 'brand_id' => $this->brand->id, 'config' => ['store_domain' => 'cp.myshopify.com'], 'secrets' => ['access_token' => 'shpat_x']]);
    app(Settings::class)->set(Catalogue::STORE_SETTING, $this->connection->id, SettingScope::Brand, $this->brand->id, $this->admin);
});

it('reads the store page by page into the catalogue, mapping options, images, variants and text details', function () {
    Http::fakeSequence()
        ->push(shopifyPage([shopifyProduct('1', 'Whey Protein Isolate')], more: true))
        ->push(shopifyPage([shopifyProduct('2', 'Creatine', ['options' => [['name' => 'Title', 'values' => ['Default Title']]], 'variants' => ['nodes' => [
            ['id' => 'gid://shopify/ProductVariant/21', 'title' => 'Default Title', 'sku' => null, 'price' => '19.99', 'availableForSale' => true, 'position' => 1, 'selectedOptions' => [['name' => 'Title', 'value' => 'Default Title']], 'image' => null],
        ]], 'onlineStoreUrl' => null, 'status' => 'DRAFT'])]));

    $sync = app(Catalogue::class)->sync($this->brand, 'manual', $this->admin);

    expect($sync->status)->toBe('ok')->and($sync->products)->toBe(2)->and($sync->added)->toBe(2);

    $whey = Product::where('handle', 'whey-protein-isolate')->sole();
    expect($whey->options)->toBe(['Size' => ['1 kg', '2 kg'], 'Flavour' => ['Vanilla']])
        ->and($whey->tags)->toBe(['whey', 'isolate'])
        ->and($whey->details)->toBe(['Nutritional fact' => "Protein 27 g\nCarbs 1 g", 'Benefits' => 'Builds muscle'])
        ->and($whey->isLive())->toBeTrue()
        ->and($whey->images)->toHaveCount(1)
        ->and($whey->variants->map->label()->all())->toBe(['1 kg · Vanilla', '2 kg · Vanilla'])
        ->and($whey->variants[0]->image->url)->toBe('https://cdn.example/a.png')
        ->and($whey->variants[1]->image)->toBeNull()
        ->and($whey->variants[1]->available)->toBeFalse();

    $creatine = Product::where('handle', 'creatine')->sole();
    expect($creatine->options)->toBe([])->and($creatine->isLive())->toBeFalse()->and($creatine->variants->sole()->label())->toBe('Only size');

    // Both pages were asked for, the second with the cursor.
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => ($request['variables']['after'] ?? null) === 'next');
});

it('sends the access token to the store, with the configured API version', function () {
    Http::fake(['cp.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['shop' => ['name' => 'CP', 'myshopifyDomain' => 'cp.myshopify.com']]])]);

    expect(app(ShopifyPlugin::class)->healthCheck($this->connection)->message)->toBe('Connected to CP');
    Http::assertSent(fn ($request) => $request->header('X-Shopify-Access-Token') === ['shpat_x']);
});

it('explains a refused token', function () {
    Http::fake(['*' => Http::response(['errors' => 'unauthorized'], 401)]);

    $sync = app(Catalogue::class)->sync($this->brand);

    expect($sync->status)->toBe('failed')->and($sync->error)->toContain('read_products');
});

<?php

use App\Auth\Role;
use App\BrandKit\BrandKits;
use App\BrandKit\Kind;
use App\Composer\ChromeRenderer;
use App\Composer\ComposeException;
use App\Composer\Composer;
use App\Composer\Layouts;
use App\Composer\Renderer;
use App\Images\ImageModels;
use App\Livewire\Layouts\LayoutsPage;
use App\Models\Connection;
use App\Models\CreativeRequest;
use App\Models\LayoutTemplate;
use App\Models\Output;
use App\Models\Placement;
use App\Models\Product;
use App\Platforms\PlatformLibrary;
use App\Requests\Requests;
use App\Review\Reviewer;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeRemover;
use Tests\Support\FakeRenderer;
use Tests\Support\FakeReviewer;

beforeEach(function () {
    Storage::fake();
    app()->instance(Reviewer::class, new FakeReviewer);
    $this->renderer = new FakeRenderer;
    app()->instance(Renderer::class, $this->renderer);
    $this->admin = signInAs(Role::Admin);
    $this->brand = makeBrand(['name' => 'Canadian Protein']);
    app(PlatformLibrary::class)->addStarters($this->admin);
    app(ImageModels::class)->addStarters($this->admin);
    Connection::factory()->create(['plugin' => 'gemini', 'name' => 'Gemini', 'brand_id' => null, 'config' => [], 'secrets' => ['api_key' => 'k'], 'status' => Connection::STATUS_CONNECTED]);
    app(Settings::class)->set('generation.per_ratio', 1, SettingScope::Platform, 0, $this->admin);

    // A kit with a colour, a font, a logo and a transparent pack shot for the variant.
    $kits = app(BrandKits::class);
    $kits->saveRows($this->brand, Kind::Colour, ['n1' => ['name' => 'Red', 'hex' => '#E4002B', 'role' => 'primary', 'use' => 'both']], $this->admin);
    $kits->saveRows($this->brand, Kind::Font, ['n1' => ['role' => 'headline', 'family' => 'Montserrat', 'licensed' => true, 'licence' => 'Google Fonts']], $this->admin);
    $kits->saveRows($this->brand, Kind::Cta, ['n1' => ['text' => ['en' => 'Shop now', 'fr' => 'Magasinez']]], $this->admin);
    $kits->addFile($this->brand, Kind::Logo, UploadedFile::fake()->image('logo.png', 400, 160), $this->admin, ['variant' => 'full', 'tone' => 'any']);
    $this->whey = Product::factory()->create(['brand_id' => $this->brand->id, 'title' => 'Whey Protein Isolate']);
    $this->vanilla = $this->whey->variants()->create(['external_id' => 'v1', 'title' => '1 kg / Vanilla', 'price' => '49.99', 'available' => true, 'options' => ['Size' => '1 kg', 'Flavour' => 'Vanilla'], 'position' => 1]);
    $kits->addFile($this->brand, Kind::PackShot, UploadedFile::fake()->image('tub.png', 300, 500), $this->admin, ['product_variant_id' => $this->vanilla->id]);

    $this->feed = Placement::where('key', 'feed-portrait')->whereHas('platform', fn ($q) => $q->where('key', 'instagram'))->sole();
    $this->story = Placement::where('key', 'stories')->whereHas('platform', fn ($q) => $q->where('key', 'instagram'))->sole();
    fakeLlm([['submit_plan', ['title' => 'Whey weekend', 'summary' => 'S', 'offer' => ['headline' => '20% off whey isolate', 'details' => 'This weekend only'], 'cta' => 'Shop now', 'product_variant_id' => $this->vanilla->id, 'image_prompt' => 'A counter.', 'image_model' => 'gemini/gemini-nano-banana-2.1']]]);
    $this->requests = app(Requests::class);
    $this->request = $this->requests->create($this->brand, ['brief' => 'Marketing image for a sale on whey protein isolate, 20% off this weekend.', 'languages' => ['en'], 'output' => 'ad', 'copy' => 'all', 'placement_ids' => [(string) $this->feed->id, (string) $this->story->id]], $this->admin);
    $this->requests->plan($this->request);

    $im = imagecreatetruecolor(64, 80);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 180, 160));
    ob_start();
    imagepng($im);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode((string) ob_get_clean())]]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 1120]])]);
});

it('composes a finished ad per placement from each clean image, inside the safe zone, at the exact size, in the placement\'s format, and checks it', function () {
    $this->requests->confirm($this->request->fresh(), $this->admin);
    $request = $this->request->fresh();

    expect($request->status)->toBe(CreativeRequest::DONE)
        ->and(Output::where('kind', Output::IMAGE)->count())->toBe(2)
        ->and(Output::where('kind', Output::AD)->count())->toBe(2);

    $ad = Output::where('kind', Output::AD)->where('placement_id', $this->story->id)->sole();
    $image = Output::where('kind', Output::IMAGE)->where('ratio', '9:16')->sole();
    expect($ad->parent_id)->toBe($image->id)->and($ad->width)->toBe(1080)->and($ad->height)->toBe(1920)->and($ad->mime)->toBe('image/png')->and($ad->language)->toBe('en')
        ->and($ad->layout_template_id)->toBeNull()
        ->and($ad->passedChecks())->toBeTrue()->and(collect($ad->checks)->pluck('check')->all())->toBe(['size', 'file_size', 'safe_zone', 'product'])
        ->and(collect($ad->checks)->firstWhere('check', 'product')['note'])->toBe('Pack shot placed')
        ->and(Storage::disk()->exists($ad->path))->toBeTrue()->and(Storage::disk()->exists($ad->thumb_path))->toBeTrue()
        ->and($ad->downloadName())->toBe('whey-weekend-instagram-stories-en-1.png');

    // The page: the clean image, the words, the kit and the pack shot, every box inside the story's safe zone.
    $render = collect($this->renderer->renders)->first(fn ($r) => $r['height'] === 1920);
    expect($render['html'])->toContain('20% off whey isolate', 'This weekend only', 'Shop now', '#E4002B', 'Montserrat', 'class="product"', 'class="logo"', 'width:1080px;height:1920px')
        ->and($render['assets'])->toContain('image.png', 'logo.png', 'product.png');
    expect($render['html'])->toContain('top:'.(250 + 54).'px'); // The content area starts at the safe top plus a 5% margin of the short side.

    $this->get(route('requests.show', [$this->brand, $request]))->assertOk()->assertSee('Finished ads')->assertSee('Checks passed')->assertSee('Instagram · Stories');
    $this->get($ad->url().'?download=1')->assertOk()->assertDownload('whey-weekend-instagram-stories-en-1.png');
});

it('uses the brand\'s own layout for the shape, keeps the words clear of the logo and notes a missing pack shot', function () {
    $layouts = app(Layouts::class);
    $template = $layouts->save($this->brand, null, ['name' => 'Big words', 'ratio' => '4:5', ...LayoutTemplate::defaults('4:5'), 'headline_position' => 'top', 'logo_corner' => 'top-right', 'text_size' => 'large', 'price_badge' => true, 'legal' => true], $this->admin);
    expect($template->is_default)->toBeTrue();
    app(BrandKits::class)->items($this->brand, Kind::PackShot)->each(fn ($i) => app(BrandKits::class)->remove($i, $this->admin));

    $this->requests->confirm($this->request->fresh(), $this->admin);

    $ad = Output::where('kind', Output::AD)->where('placement_id', $this->feed->id)->sole();
    $render = collect($this->renderer->renders)->first(fn ($r) => $r['height'] === 1350);
    expect($ad->layout_template_id)->toBe($template->id)->and($ad->passedChecks())->toBeFalse()
        ->and($ad->failedChecks()[0])->toContain('No transparent pack shot for Whey Protein Isolate (1 kg · Vanilla)')
        ->and($render['html'])->not->toContain('class="product"')->and($render['html'])->toContain('class="logo"')->not->toContain('class="badge"'); // the badge gives way to a top-right logo

    // With a cut-out of the variant's store photo, the product is on the ad after all.
    $photo = $this->whey->images()->create(['external_id' => 'img', 'url' => 'https://cdn.example/whey.png', 'position' => 0, 'cutout_path' => 'catalogue/1/cutouts/1.png']);
    $this->vanilla->update(['product_image_id' => $photo->id]);
    Storage::disk()->put('catalogue/1/cutouts/1.png', FakeRemover::transparentPng());
    $content = app(Composer::class)->content($this->request->fresh(), 'en', null);
    expect($content->product)->toBe('catalogue/1/cutouts/1.png')->and($content->productNote)->toBe('');
});

it('refuses an ad that came out the wrong size or too big, and converts to what the placement takes', function () {
    app()->instance(Renderer::class, $this->renderer = new FakeRenderer(forceWidth: 1000));
    $this->feed->update(['max_image_kb' => 1, 'image_types' => ['png']]);

    $this->requests->confirm($this->request->fresh(), $this->admin);

    $ad = Output::where('kind', Output::AD)->where('placement_id', $this->feed->id)->sole();
    expect($ad->mime)->toBe('image/png')->and($ad->passedChecks())->toBeFalse()
        ->and($ad->failedChecks())->toHaveCount(2)->and($ad->failedChecks()[0])->toContain('came out 1000 × 1350, not 1080 × 1350')->and($ad->failedChecks()[1])->toContain('over the placement\'s 1 KB');
});

it('writes a renderer failure on the shape and keeps the clean images', function () {
    app()->instance(Renderer::class, new class implements Renderer
    {
        public function render(string $html, int $width, int $height, string $directory): string
        {
            throw new ComposeException('Chrome made no picture.');
        }
    });

    $this->requests->confirm($this->request->fresh(), $this->admin);
    $request = $this->request->fresh();

    expect($request->status)->toBe(CreativeRequest::DONE)->and(Output::where('kind', Output::AD)->count())->toBe(0)
        ->and($request->generations[0]->compose_error)->toBe('Chrome made no picture.');
    $this->get(route('requests.show', [$this->brand, $request]))->assertOk()->assertSee('Finished ads: Chrome made no picture.');
});

it('makes no ads for a clean-image request', function () {
    $this->request->update(['output' => 'image']);
    $this->requests->confirm($this->request->fresh(), $this->admin);
    expect(Output::where('kind', Output::AD)->count())->toBe(0)->and($this->renderer->renders)->toBe([]);
});

it('lets the team add, adjust, preview, copy, choose and remove layouts', function () {
    $page = Livewire::test(LayoutsPage::class, ['brand' => $this->brand])->assertSee('built-in ones are used')
        ->call('addStarters')->assertSee('Used for 4:5')->assertSee('Used for 9:16');
    $template = LayoutTemplate::where('brand_id', $this->brand->id)->where('ratio', '9:16')->sole();
    expect(LayoutTemplate::where('brand_id', $this->brand->id)->count())->toBe(count(app(Layouts::class)->ratios()));

    $page->call('openForm', $template->id)->assertSet('form.headline_position', 'top')->assertSet('form.product_position', 'bottom')
        ->set('form.name', '')->call('save')->assertHasErrors('form.name')
        ->set('form.name', 'Story')->set('form.headline_position', 'middle')->call('showPreview')->assertSet('previewError', null)
        ->call('save')->assertHasNoErrors()->assertSet('open', null);
    expect($template->fresh()->name)->toBe('Story')->and($template->fresh()->setting('headline_position'))->toBe('middle')
        ->and($page->get('preview'))->toBeNull()->and($this->renderer->renders[0]['width'])->toBe(1080)->and($this->renderer->renders[0]['html'])->toContain('20% off this weekend');

    $page->call('duplicate', $template->id)->assertSee('Story (copy)');
    $copy = LayoutTemplate::where('name', 'Story (copy)')->sole();
    $page->call('makeDefault', $copy->id);
    expect($copy->fresh()->is_default)->toBeTrue()->and($template->fresh()->is_default)->toBeFalse()
        ->and(app(Layouts::class)->templateFor($this->brand, '9:16')->id)->toBe($copy->id);

    $page->call('askRemove', $copy->id)->assertSee('Yes, remove')->call('remove', $copy->id);
    expect(LayoutTemplate::find($copy->id))->toBeNull()->and($template->fresh()->is_default)->toBeTrue();

    signInAs(Role::Viewer);
    Livewire::test(LayoutsPage::class, ['brand' => $this->brand])->assertDontSee('Add a layout')->call('openForm')->assertForbidden();
});

it('renders a real page with Chrome when it is installed', function () {
    $chrome = trim((string) shell_exec('command -v google-chrome chromium chromium-browser 2>/dev/null | head -1'));
    if ($chrome === '') {
        $this->markTestSkipped('No Chrome on this machine.');
    }

    app(Settings::class)->set('composer.chrome_path', $chrome, SettingScope::Platform, 0, $this->admin);
    app()->bind(Renderer::class, ChromeRenderer::class);

    $png = app(Composer::class)->preview($this->brand, LayoutTemplate::builtIn($this->brand, '1:1'));
    $info = getimagesizefromstring($png);
    expect($info[0])->toBe(1080)->and($info[1])->toBe(1080)->and($info['mime'])->toBe('image/png');
});

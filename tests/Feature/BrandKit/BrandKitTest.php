<?php

use App\Auth\Role;
use App\Auth\Roles;
use App\BrandKit\BrandKits;
use App\BrandKit\Kind;
use App\Livewire\BrandKit\KitItems;
use App\Livewire\BrandKit\KitText;
use App\Models\BrandKitItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake(config('filesystems.default'));
    $this->admin = signInAs(Role::Admin);
    $this->brand = makeBrand(['name' => 'BioSteel']);
    $this->kits = app(BrandKits::class);
});

/**
 * A PNG with a see-through background, as a logo or pack shot should be.
 */
function transparentPng(string $name = 'logo.png', int $size = 40): UploadedFile
{
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledellipse($image, intdiv($size, 2), intdiv($size, 2), intdiv($size, 2), intdiv($size, 2), imagecolorallocate($image, 200, 0, 40));
    ob_start();
    imagepng($image);

    return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
}

function svgFile(string $name, string $body): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 40">'.$body.'</svg>');
}

it('saves colours as rows, in order, and checks what was typed', function () {
    try {
        $this->kits->saveRows($this->brand, Kind::Colour, ['a' => ['id' => null, 'name' => 'Red', 'hex' => 'E4002B', 'role' => 'primary', 'use' => 'both']], $this->admin);
        $this->fail('A hex code without # was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('a.hex')->and($e->errors()['a.hex'][0])->toContain('like #E4002B');
    }

    $this->kits->saveRows($this->brand, Kind::Colour, [
        'a' => ['id' => null, 'name' => ' Red ', 'hex' => '#E4002B', 'role' => 'primary', 'use' => 'both'],
        'b' => ['id' => null, 'name' => 'Ice', 'hex' => '#F2F7FA', 'role' => 'neutral', 'use' => 'background'],
    ], $this->admin);

    $colours = $this->kits->items($this->brand, Kind::Colour);
    expect($colours->map->label()->all())->toBe(['Red #E4002B', 'Ice #F2F7FA'])
        ->and($colours[0]->position)->toBe(0)->and($colours[1]->position)->toBe(1)
        ->and(Activity::where('log_name', 'brand-kit')->latest('id')->first()->description)->toBe("Saved BioSteel's colours (2)");

    // Rows with an id update that item; a row for a gone item is refused.
    $this->kits->saveRows($this->brand, Kind::Colour, ['x' => ['id' => $colours[1]->id, 'name' => 'Ice blue', 'hex' => '#F2F7FA', 'role' => 'secondary', 'use' => 'background']], $this->admin);
    expect($colours[1]->fresh()->value('name'))->toBe('Ice blue')->and($colours[1]->fresh()->value('role'))->toBe('secondary')->and(BrandKitItem::count())->toBe(2);

    $this->kits->saveRows($this->brand, Kind::Colour, ['x' => ['id' => 999, 'name' => 'Ghost', 'hex' => '#000000', 'role' => 'accent', 'use' => 'text']], $this->admin);
})->throws(ValidationException::class);

it('keeps an uploaded logo named by its content, makes a preview, and knows whether it is transparent', function () {
    $logo = $this->kits->addFile($this->brand, Kind::Logo, transparentPng(), $this->admin, ['variant' => 'mark']);

    expect($logo->path)->toMatch('#^brand-kit/'.$this->brand->id.'/logo/[a-f0-9]{40}\.png$#')
        ->and($logo->thumb_path)->toEndWith('.thumb.webp')
        ->and(Storage::disk()->exists($logo->path))->toBeTrue()
        ->and(Storage::disk()->exists($logo->thumb_path))->toBeTrue()
        ->and($logo->width)->toBe(40)->and($logo->height)->toBe(40)
        ->and($logo->mime)->toBe('image/png')
        ->and($logo->isTransparent())->toBeTrue()
        ->and($logo->value('variant'))->toBe('mark')->and($logo->value('tone'))->toBe('any')
        ->and($logo->label())->toBe('icon-only mark')
        ->and(Activity::where('log_name', 'brand-kit')->latest('id')->first()->description)->toBe("Added a logo to BioSteel's kit: logo.png");

    $photo = $this->kits->addFile($this->brand, Kind::PackShot, UploadedFile::fake()->image('shot.png', 600, 900), $this->admin);
    expect($photo->isTransparent())->toBeFalse()->and($photo->width)->toBe(600);

    $svg = $this->kits->addFile($this->brand, Kind::Logo, svgFile('full.svg', '<rect width="120" height="40"/>'), $this->admin);
    expect($svg->mime)->toBe('image/svg+xml')->and($svg->thumb_path)->toBeNull()->and($svg->width)->toBe(120)->and($svg->height)->toBe(40)->and($svg->isTransparent())->toBeNull()
        ->and($svg->thumbUrl())->toBe($svg->url());
});

it('refuses files of the wrong type, files that are too big, and SVGs that could run something', function () {
    $refused = function (Kind $kind, UploadedFile $file, string $message) {
        try {
            $this->kits->addFile($this->brand, $kind, $file, $this->admin);
            $this->fail("{$file->getClientOriginalName()} was accepted.");
        } catch (ValidationException $e) {
            expect($e->validator->errors()->first())->toContain($message);
        }
    };

    $refused(Kind::Logo, UploadedFile::fake()->image('logo.jpg', 10, 10), 'A logo is a SVG, PNG, WEBP file');
    $refused(Kind::PackShot, UploadedFile::fake()->image('shot.jpg', 10, 10), 'PNG, WEBP file');
    $refused(Kind::Logo, svgFile('bad.svg', '<script>alert(1)</script>'), 'scripts, links or embedded content');
    $refused(Kind::Logo, svgFile('bad.svg', '<rect onload="x()"/>'), 'scripts, links or embedded content');
    $refused(Kind::Logo, svgFile('bad.svg', '<image href="https://evil.example/x.png"/>'), 'scripts, links or embedded content');
    $refused(Kind::Logo, UploadedFile::fake()->createWithContent('fake.png', 'not an image'), "isn't an image");
    $refused(Kind::Colour, UploadedFile::fake()->image('c.png'), "don't take files");

    app(Settings::class)->set('brand_kit.max_upload_mb', 1, SettingScope::Platform, 0, $this->admin);
    $refused(Kind::Reference, UploadedFile::fake()->create('big.jpg', 2048, 'image/jpeg'), 'bigger than 1 MB');

    // A plain SVG with an internal <use> is fine.
    expect($this->kits->addFile($this->brand, Kind::Logo, svgFile('ok.svg', '<defs><path id="p" d="M0 0h10v10z"/></defs><use href="#p"/>'), $this->admin))->toBeInstanceOf(BrandKitItem::class);
});

it('serves kit files to people with access to the brand and to no one else', function () {
    $logo = $this->kits->addFile($this->brand, Kind::Logo, transparentPng(), $this->admin);
    $other = makeBrand(['name' => 'Other']);

    $this->get($logo->url())->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'max-age=86400, private')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get($logo->thumbUrl())->assertOk()->assertHeader('Content-Type', 'image/webp');
    $this->get(route('brand-kit.file', ['brand' => $other, 'item' => $logo]))->assertNotFound();

    $svg = $this->kits->addFile($this->brand, Kind::Logo, svgFile('full.svg', '<rect width="1" height="1"/>'), $this->admin);
    $this->get($svg->url())->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'");

    $outsider = signInAs(null);
    app(Roles::class)->assign($outsider, Role::Viewer, $other->id);
    $this->get($logo->url())->assertForbidden();
    $this->get(route('brand-kit', $this->brand))->assertForbidden();
});

it('removes an item and its file, keeping a file another item still shares', function () {
    $one = $this->kits->addFile($this->brand, Kind::Reference, transparentPng('a.png'), $this->admin);
    $two = $this->kits->addFile($this->brand, Kind::Reference, transparentPng('b.png'), $this->admin);
    expect($one->path)->toBe($two->path);

    $this->kits->remove($one, $this->admin);
    expect(Storage::disk()->exists($two->path))->toBeTrue()->and(BrandKitItem::count())->toBe(1)
        ->and(Activity::where('log_name', 'brand-kit')->latest('id')->first()->description)->toBe("Removed the reference image \"a.png\" from BioSteel's kit");

    $this->kits->remove($two, $this->admin);
    expect(Storage::disk()->exists($two->path))->toBeFalse()->and(Storage::disk()->exists($two->thumb_path))->toBeFalse()->and(BrandKitItem::count())->toBe(0);
});

it('knows what the kit still needs before the brand can generate', function () {
    app(Settings::class)->set('brand_kit.min_references', 2, SettingScope::Brand, $this->brand->id, $this->admin);

    expect($this->kits->isReady($this->brand))->toBeFalse()
        ->and($this->kits->missing($this->brand))->toBe(['Logos', 'Colours', 'Fonts', 'Voice', 'Visual direction', 'Style references']);

    $this->kits->addFile($this->brand, Kind::Logo, transparentPng(), $this->admin, ['variant' => 'mark']);
    $this->kits->saveRows($this->brand, Kind::Colour, ['a' => ['id' => null, 'name' => 'Grey', 'hex' => '#888888', 'role' => 'neutral', 'use' => 'text']], $this->admin);
    $this->kits->saveRows($this->brand, Kind::Font, [
        'a' => ['id' => null, 'role' => 'headline', 'family' => 'Montserrat', 'licensed' => true, 'licence' => 'Google Fonts'],
        'b' => ['id' => null, 'role' => 'body', 'family' => 'Inter', 'licensed' => false, 'licence' => ''],
    ], $this->admin);
    $this->kits->addFile($this->brand, Kind::Reference, transparentPng('r1.png'), $this->admin);

    $checks = collect($this->kits->readiness($this->brand))->keyBy('key');
    expect($checks['logo']['ok'])->toBeFalse()->and($checks['logo']['detail'])->toBe('Only the mark: the full logo is needed')
        ->and($checks['colour']['detail'])->toBe('No primary colour yet')
        ->and($checks['font']['detail'])->toContain('each marked as licensed')
        ->and($checks['reference']['detail'])->toBe('1 of 2')
        ->and($checks['best_ad']['ok'])->toBeFalse()->and($this->kits->isReady($this->brand))->toBeFalse();

    $this->kits->addFile($this->brand, Kind::Logo, transparentPng('full.png'), $this->admin);
    $this->kits->saveRows($this->brand, Kind::Colour, ['a' => ['id' => null, 'name' => 'Red', 'hex' => '#E4002B', 'role' => 'primary', 'use' => 'both']], $this->admin);
    $fonts = $this->kits->items($this->brand, Kind::Font);
    $this->kits->saveRows($this->brand, Kind::Font, ['b' => ['id' => $fonts[1]->id, 'role' => 'body', 'family' => 'Inter', 'licensed' => true, 'licence' => 'Google Fonts']], $this->admin);
    $this->kits->saveText($this->brand, ['tone' => ['en' => 'Confident, warm.']], ['mood' => 'Bright'], $this->admin);
    // The same picture twice is still two references.
    $this->kits->addFile($this->brand, Kind::Reference, transparentPng('r2.png'), $this->admin);

    expect($this->kits->isReady($this->brand))->toBeTrue()->and($this->kits->missing($this->brand))->toBe([]);
});

it('sums the kit up for the prompts, in the language asked for with English behind it', function () {
    $this->kits->saveRows($this->brand, Kind::Colour, ['a' => ['id' => null, 'name' => 'Red', 'hex' => '#E4002B', 'role' => 'primary', 'use' => 'both']], $this->admin);
    $this->kits->saveRows($this->brand, Kind::Font, ['a' => ['id' => null, 'role' => 'headline', 'family' => 'Montserrat', 'licensed' => true, 'licence' => '']], $this->admin);
    $this->kits->saveText($this->brand, ['tone' => ['en' => 'Confident and warm.', 'fr' => 'Confiant et chaleureux.'], 'avoid' => ['en' => 'cheap, miracle']], ['mood' => 'Bright', 'avoid' => 'Neon'], $this->admin);
    $this->kits->saveRows($this->brand, Kind::Cta, ['a' => ['id' => null, 'text' => ['en' => 'Shop now', 'fr' => 'Magasinez']], 'b' => ['id' => null, 'text' => ['en' => 'Get 20% off', 'fr' => '']]], $this->admin);
    $this->kits->saveRows($this->brand, Kind::LegalLine, ['a' => ['id' => null, 'text' => ['en' => '*While supplies last.', 'fr' => ''], 'when' => 'Any sale']], $this->admin);

    $en = $this->kits->summary($this->brand);
    expect($en)->toContain('Brand: BioSteel')
        ->toContain('Colours: Red #E4002B (primary, Text and backgrounds)')
        ->toContain('Fonts: Headline: Montserrat')
        ->toContain('Voice: Confident and warm.')
        ->toContain('Words and phrases to avoid: cheap, miracle')
        ->toContain("Visual direction:\n- Mood: Bright\n- Avoid: Neon")
        ->toContain('Calls to action: Shop now; Get 20% off')
        ->toContain("Legal lines:\n- *While supplies last. (when: Any sale)");

    expect($this->kits->summary($this->brand, 'fr'))->toContain('Voice: Confiant et chaleureux.')->toContain('Words and phrases to avoid: cheap, miracle')->toContain('Calls to action: Magasinez; Get 20% off');
});

it('ties a pack shot to one of the brand\'s variants and to no other brand\'s', function () {
    $product = Product::factory()->create(['brand_id' => $this->brand->id, 'title' => 'Hydration Mix']);
    $variant = ProductVariant::create(['product_id' => $product->id, 'external_id' => 'v1', 'title' => '45 serve', 'options' => ['Size' => '45 serve'], 'position' => 0]);
    $elsewhere = ProductVariant::create(['product_id' => Product::factory()->create()->id, 'external_id' => 'v2', 'title' => 'Other', 'options' => [], 'position' => 0]);

    $shot = $this->kits->addFile($this->brand, Kind::PackShot, transparentPng('shot.png'), $this->admin, ['product_variant_id' => (string) $variant->id]);
    expect($shot->product_variant_id)->toBe($variant->id)->and($shot->label())->toBe('Hydration Mix · 45 serve')
        ->and(Kind::PackShot->field('product_variant_id')->optionsFor($this->brand))->toBe(['' => 'Not tied to a product yet', (string) $variant->id => 'Hydration Mix · 45 serve']);

    $this->kits->saveRows($this->brand, Kind::PackShot, ['x' => ['id' => $shot->id, 'product_variant_id' => (string) $elsewhere->id, 'note' => '']], $this->admin);
})->throws(ValidationException::class);

it('shows the checklist and every section, and lets a viewer look but not change', function () {
    $this->get(route('brand-kit', $this->brand))->assertOk()
        ->assertSeeInOrder(['What the kit needs', 'Not ready yet', 'Logos', 'Colours', 'Fonts', 'Voice', 'Visual direction', 'Style references', 'Best-performing ads', 'Bad examples', 'Calls to action', 'Legal lines', 'Pack shots', 'Music'])
        ->assertSee('Add logos')->assertSee('Add colour');

    signInAs(Role::Viewer);
    $this->get(route('brand-kit', $this->brand))->assertOk()->assertDontSee('Add logos')->assertDontSee('Save voice and direction');
    Livewire::test(KitItems::class, ['brand' => $this->brand, 'kind' => 'colour', 'canManage' => false])->call('add')->assertForbidden();
    Livewire::test(KitText::class, ['brand' => $this->brand, 'canManage' => false])->call('save')->assertForbidden();
});

it('adds uploads and saves rows from the page, showing what is wrong', function () {
    Livewire::test(KitItems::class, ['brand' => $this->brand, 'kind' => 'logo', 'canManage' => true])
        ->set('uploads', [transparentPng('a.png'), UploadedFile::fake()->image('b.jpg')])
        ->assertHasErrors('uploads')
        ->assertSee('b.jpg: A logo is a SVG, PNG, WEBP file')
        ->assertDispatched('kit-changed')
        ->assertSee('a.png');
    expect(BrandKitItem::where('kind', Kind::Logo)->count())->toBe(1);

    $colours = Livewire::test(KitItems::class, ['brand' => $this->brand, 'kind' => 'colour', 'canManage' => true])->call('add');
    $key = array_key_first($colours->get('rows'));
    $colours->set("rows.{$key}.name", 'Red')->set("rows.{$key}.hex", 'red')->call('save')
        ->assertHasErrors("rows.{$key}.hex")->assertSee('like #E4002B');
    expect(BrandKitItem::where('kind', Kind::Colour)->count())->toBe(0);

    $colours->set("rows.{$key}.hex", '#E4002B')->call('save')->assertHasNoErrors()->assertDispatched('kit-changed');
    $colour = BrandKitItem::where('kind', Kind::Colour)->sole();
    expect($colour->value('name'))->toBe('Red')->and($colours->get('rows'))->toHaveKey("i{$colour->id}");

    // Removing asks first.
    $colours->call('askRemove', "i{$colour->id}")->assertSee('Yes, remove')->call('keep')->assertDontSee('Yes, remove')
        ->call('askRemove', "i{$colour->id}")->call('removeRow', "i{$colour->id}");
    expect(BrandKitItem::count())->toBe(1);
});

it('puts a font file on a saved font row', function () {
    $this->kits->saveRows($this->brand, Kind::Font, ['a' => ['id' => null, 'role' => 'headline', 'family' => 'House Sans', 'licensed' => true, 'licence' => 'Bought']], $this->admin);
    $font = $this->kits->items($this->brand, Kind::Font)->sole();

    Livewire::test(KitItems::class, ['brand' => $this->brand, 'kind' => 'font', 'canManage' => true])
        ->set("rowUploads.i{$font->id}", UploadedFile::fake()->create('house.woff2', 12, 'font/woff2'))
        ->assertHasNoErrors()->assertSee('house.woff2');
    expect($font->fresh()->path)->toMatch('#/font/[a-f0-9]{40}\.woff2$#')->and($font->fresh()->mime)->toBe('font/woff2');

    // A new row has nothing to attach to yet.
    $page = Livewire::test(KitItems::class, ['brand' => $this->brand, 'kind' => 'font', 'canManage' => true])->call('add');
    $new = collect(array_keys($page->get('rows')))->first(fn ($k) => str_starts_with($k, 'n'));
    $page->set("rowUploads.{$new}", UploadedFile::fake()->create('x.ttf', 12))->assertHasErrors("rowUploads.{$new}");
});

it('saves the voice and direction from the page', function () {
    Livewire::test(KitText::class, ['brand' => $this->brand, 'canManage' => true])
        ->set('voice.tone.en', ' Confident. ')->set('voice.tone.fr', 'Confiant.')->set('direction.mood', 'Bright')
        ->call('save')->assertHasNoErrors()->assertDispatched('kit-changed');

    $kit = $this->kits->for($this->brand);
    expect($kit->voice('tone'))->toBe('Confident.')->and($kit->voice('tone', 'fr'))->toBe('Confiant.')->and($kit->direction('mood'))->toBe('Bright')->and($kit->updated_by)->toBe($this->admin->id)
        ->and(Activity::where('log_name', 'brand-kit')->sole()->description)->toBe("Changed BioSteel's voice and visual direction");
});

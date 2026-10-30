<?php

use App\Auth\Role;
use App\Livewire\Platforms\PlatformLibraryPage;
use App\Models\Placement;
use App\Models\PlacementPreset;
use App\Models\Platform;
use App\Platforms\AspectRatio;
use App\Platforms\CopyField;
use App\Platforms\PlatformLibrary;
use App\Platforms\Starters;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = signInAs(Role::Admin);
    $this->library = app(PlatformLibrary::class);
});

function placementInput(array $more = []): array
{
    return ['name' => 'Feed (4:5)', 'width' => 1080, 'height' => 1350, 'formats' => ['image'], 'image_types' => ['jpg', 'png'], 'max_image_kb' => 30720,
        'safe_top' => 0, 'safe_bottom' => 0, 'safe_left' => 0, 'safe_right' => 0, 'video_types' => [], 'copy_limits' => ['primary_text' => 125, 'headline' => 40], 'notes' => '', ...$more];
}

it('snaps sizes to the common aspect ratios so one generation serves every placement of that shape', function () {
    expect(AspectRatio::of(1080, 1350))->toBe('4:5')
        ->and(AspectRatio::of(1080, 1080))->toBe('1:1')
        ->and(AspectRatio::of(1080, 1920))->toBe('9:16')
        ->and(AspectRatio::of(1920, 1080))->toBe('16:9')
        ->and(AspectRatio::of(1200, 628))->toBe('1.91:1')
        ->and(AspectRatio::of(1200, 627))->toBe('1.91:1')
        ->and(AspectRatio::of(1000, 1500))->toBe('2:3')
        ->and(AspectRatio::of(960, 1200))->toBe('4:5')
        ->and(AspectRatio::of(1200, 600))->toBe('2:1')
        ->and(AspectRatio::of(1000, 411))->toBe('1000:411')
        ->and(AspectRatio::isPortrait('9:16'))->toBeTrue()->and(AspectRatio::isLandscape('1.91:1'))->toBeTrue()->and(AspectRatio::isPortrait('1:1'))->toBeFalse();
});

it('adds the starting platforms once, each marked as not checked, with their sets', function () {
    expect($this->library->addStarters($this->admin))->toBe(count(Starters::platforms()))
        ->and(Platform::count())->toBe(count(Starters::platforms()))
        ->and(Platform::where('verified_at', '!=', null)->count())->toBe(0)
        ->and(Placement::count())->toBe(collect(Starters::platforms())->sum(fn ($p) => count($p['placements'])))
        ->and($this->library->presets()->pluck('name')->all())->toBe(array_keys(Starters::presets()))
        ->and(Activity::where('log_name', 'platforms')->sole()->description)->toBe('Added the starting platforms ('.count(Starters::platforms()).')');

    $instagram = Platform::where('key', 'instagram')->sole();
    $reels = $instagram->placements->firstWhere('key', 'reels');
    expect($reels->ratio)->toBe('9:16')->and($reels->takesVideo())->toBeTrue()->and($reels->takesImages())->toBeFalse()
        ->and($reels->safeZone())->toBe(['top' => 250, 'bottom' => 420, 'left' => 60, 'right' => 150])
        ->and($reels->copyLimit(CopyField::Caption))->toBe(2200)->and($reels->copyLimit(CopyField::Headline))->toBeNull()
        ->and(PlacementPreset::where('name', 'All vertical video')->sole()->placements()->map->label()->all())->toContain('Instagram · Reels', 'TikTok · In-feed and ads', 'YouTube · Shorts');

    // Again: nothing doubled, and an edited row is left alone.
    $reels->update(['safe_bottom' => 500]);
    expect($this->library->addStarters($this->admin))->toBe(0)->and(Placement::count())->toBe(collect(Starters::platforms())->sum(fn ($p) => count($p['placements'])))
        ->and($reels->fresh()->safe_bottom)->toBe(500);

    // Every starting placement has a sensible shape and takes something.
    foreach (Placement::all() as $placement) {
        expect($placement->formats)->not->toBeEmpty()
            ->and($placement->takesImages() ? $placement->image_types : [])->toBe($placement->takesImages() ? $placement->image_types : [])
            ->and($placement->safe_top + $placement->safe_bottom)->toBeLessThan($placement->height);
    }
});

it('saves a platform and its placements, working out the ratio, and refuses what makes no sense', function () {
    $platform = $this->library->savePlatform(null, ['name' => 'Instagram', 'prompt_note' => ' Polished but real. '], $this->admin);
    expect($platform->key)->toBe('instagram')->and($platform->prompt_note)->toBe('Polished but real.')->and($platform->enabled)->toBeTrue();

    $feed = $this->library->savePlacement($platform, null, placementInput(), $this->admin);
    expect($feed->key)->toBe('feed-45')->and($feed->ratio)->toBe('4:5')->and($feed->copyLimits())->toBe(['primary_text' => 125, 'headline' => 40])
        ->and($feed->video_types)->toBe([])->and($feed->label())->toBe('Instagram · Feed (4:5)')
        ->and(Activity::where('log_name', 'platforms')->latest('id')->first()->description)->toBe('Added the placement "Instagram · Feed (4:5)" (1080 × 1350)');

    // A second placement with the same name gets its own key.
    expect($this->library->savePlacement($platform, null, placementInput(), $this->admin)->key)->toBe('feed-45-2');

    $refused = function (array $input, string $field, string $message) use ($platform) {
        try {
            $this->library->savePlacement($platform, null, $input, $this->admin);
            $this->fail('Accepted.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($field)->and($e->errors()[$field][0])->toContain($message);
        }
    };
    $refused(placementInput(['formats' => []]), 'formats', 'Tick image, video or both');
    $refused(placementInput(['image_types' => []]), 'image_types', 'at least one image file type');
    $refused(placementInput(['formats' => ['video'], 'video_types' => ['mp4'], 'video_min_seconds' => 30, 'video_max_seconds' => 10]), 'video_max_seconds', "can't be shorter than the shortest");
    $refused(placementInput(['safe_top' => 700, 'safe_bottom' => 700]), 'safe_top', 'leaves no room');
    $refused(placementInput(['width' => 10]), 'width', 'at least 100');

    // Video fields are only kept when the placement takes video.
    $both = $this->library->savePlacement($platform, $feed, placementInput(['formats' => ['image', 'video'], 'video_types' => ['mp4'], 'max_video_mb' => 4096, 'video_min_seconds' => 1, 'video_max_seconds' => 60]), $this->admin);
    expect($both->video_max_seconds)->toBe(60);
    $imageAgain = $this->library->savePlacement($platform, $feed, placementInput(['formats' => ['image'], 'video_types' => ['mp4'], 'video_max_seconds' => 60]), $this->admin);
    expect($imageAgain->video_types)->toBe([])->and($imageAgain->video_max_seconds)->toBeNull();

    $this->library->savePlatform(null, ['name' => 'Instagram'], $this->admin);
})->throws(ValidationException::class, 'There is already a platform with this name.');

it('groups placements by the ratio they are generated at and offers only what is switched on', function () {
    $this->library->addStarters($this->admin);
    $instagram = Platform::where('key', 'instagram')->sole();
    $tiktok = Platform::where('key', 'tiktok')->sole();

    $this->library->enablePlacement($instagram->placements->firstWhere('key', 'feed-square'), false, $this->admin);
    $this->library->enablePlatform($tiktok, false, $this->admin);

    $available = $this->library->available();
    expect($available->map->label()->all())->not->toContain('Instagram · Feed (1:1)', 'TikTok · In-feed and ads')->toContain('Instagram · Feed (4:5)')
        ->and(array_keys($this->library->byRatio($available)))->toContain('1:1', '4:5', '9:16', '16:9', '1.91:1', '2:3', '2:1')
        ->and($this->library->byRatio($available)['9:16']->map->label()->all())->not->toContain('TikTok · In-feed and ads')
        ->and(PlacementPreset::where('name', 'All vertical video')->sole()->placements()->map->label()->all())->not->toContain('TikTok · In-feed and ads');
});

it('keeps sets of placements and forgets removed placements from them', function () {
    $this->library->addStarters($this->admin);
    $ids = Placement::whereIn('key', ['stories', 'reels'])->pluck('id')->all();

    $preset = $this->library->savePreset(null, 'Vertical', $ids, $this->admin);
    expect($preset->placement_ids)->toBe($ids)->and($preset->placements()->count())->toBe(count($ids));

    $gone = Placement::find($ids[0]);
    $this->library->deletePlacement($gone, $this->admin);
    expect($preset->fresh()->placement_ids)->toBe(array_values(array_slice($ids, 1)));

    $this->library->deletePlatform(Platform::where('key', 'facebook')->sole(), $this->admin);
    expect(Placement::whereHas('platform', fn ($q) => $q->where('key', 'facebook'))->count())->toBe(0)
        ->and($preset->fresh()->placements()->map(fn ($p) => $p->platform->key)->unique()->all())->not->toContain('facebook');

    $this->library->savePreset(null, 'Empty', [], $this->admin);
})->throws(ValidationException::class, 'Tick at least one placement.');

it('is admins only, and lets them add starters, edit a placement, mark a platform checked and remove things from the page', function () {
    $page = Livewire::test(PlatformLibraryPage::class)->call('addStarters')->assertSee('Instagram')->assertSee('Not checked against the platform');
    $instagram = Platform::where('key', 'instagram')->sole();
    $feed = $instagram->placements->firstWhere('key', 'feed-portrait');

    $page->call('markChecked', $instagram->id, true)->assertSee("docs by {$this->admin->name}");

    $page->call('openPlacement', $instagram->id, $feed->id)->assertSet('placementForm.width', 1080)->assertSet('placementForm.copy_limits.caption', 2200)
        ->set('placementForm.height', 'tall')->call('savePlacement')->assertHasErrors('placementForm.height')
        ->set('placementForm.height', 1440)->set('placementForm.width', 1152)->call('savePlacement')->assertHasNoErrors()->assertSet('placementOpen', null);
    expect($feed->fresh()->ratio)->toBe('4:5')->and($feed->fresh()->height)->toBe(1440);

    $page->call('openPlatform')->set('platformForm.name', 'Threads')->call('savePlatform')->assertHasNoErrors()->assertSee('Threads');
    $threads = Platform::where('name', 'Threads')->sole();
    $page->call('openPlacement', $threads->id)->set('placementForm.name', 'Post')->set('placementForm.width', 1080)->set('placementForm.height', 1350)->call('savePlacement')->assertHasNoErrors();
    expect($threads->placements()->count())->toBe(1);

    $page->call('openPreset')->set('presetForm.name', 'Threads only')->set('presetForm.placement_ids', [(string) $threads->placements()->first()->id])->call('savePreset')->assertHasNoErrors()->assertSee('Threads only');

    $page->call('askRemove', "platform-{$threads->id}")->assertSee('Yes, remove it and its placements')->call('keep')->assertDontSee('Yes, remove it and its placements')
        ->call('askRemove', "platform-{$threads->id}")->call('remove', "platform-{$threads->id}")->assertDontSee('Add a placement on Threads');
    expect(Platform::where('name', 'Threads')->exists())->toBeFalse()->and(PlacementPreset::where('name', 'Threads only')->sole()->placement_ids)->toBe([]);

    signInAs(Role::Creator);
    $this->get(route('platforms'))->assertForbidden();
});

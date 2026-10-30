<?php

use App\Auth\Role;
use App\Images\ImageModels;
use App\Models\Connection;
use App\Models\CreativeRequest;
use App\Models\Output;
use App\Models\Placement;
use App\Platforms\PlatformLibrary;
use App\Requests\Changes;
use App\Requests\Requests;
use App\Review\Retention;
use App\Review\Reviewer;
use App\Review\Shortlist;
use App\Scheduler\Scheduler;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeReviewer;

beforeEach(function () {
    Storage::fake();
    app()->instance(Reviewer::class, new FakeReviewer);
    Sleep::fake();
    $this->admin = signInAs(Role::Admin);
    $this->brand = makeBrand(['name' => 'Canadian Protein']);
    app(PlatformLibrary::class)->addStarters($this->admin);
    app(ImageModels::class)->addStarters($this->admin);
    Connection::factory()->create(['plugin' => 'gemini', 'name' => 'Gemini', 'brand_id' => null, 'config' => [], 'secrets' => ['api_key' => 'k'], 'status' => Connection::STATUS_CONNECTED]);
    app(Settings::class)->set('generation.per_ratio', 2, SettingScope::Platform, 0, $this->admin);
    // One delivered per shape, so the other is rejected ("the best 1 was chosen").
    app(Settings::class)->set('review.deliver', 1, SettingScope::Platform, 0, $this->admin);

    $ids = Placement::whereIn('key', ['feed-square', 'stories'])->whereHas('platform', fn ($q) => $q->where('key', 'instagram'))->pluck('id')->map(fn ($i) => (string) $i)->all();
    fakeLlm([['submit_plan', ['title' => 'Whey weekend', 'summary' => 'S', 'image_prompt' => 'A kitchen counter.', 'image_model' => 'gemini/gemini-nano-banana-2.1']]]);
    $requests = app(Requests::class);
    $this->request = $requests->create($this->brand, ['brief' => 'Marketing image for a sale on whey protein isolate.', 'languages' => ['en'], 'output' => 'image', 'copy' => 'none', 'placement_ids' => $ids], $this->admin);
    $requests->plan($this->request);

    $im = imagecreatetruecolor(64, 64);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 180, 160));
    ob_start();
    imagepng($im);
    $png = (string) ob_get_clean();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($png)]]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 1120]])]);
    $requests->confirm($this->request->fresh(), $this->admin);
    $this->request->refresh();
    $this->retention = app(Retention::class);
});

it('deletes the files of rejected outputs after the brand\'s retention time, keeping the row, its reason and everything shortlisted', function () {
    expect($this->request->status)->toBe(CreativeRequest::DONE)->and($this->request->outputs()->count())->toBe(4)
        ->and($this->request->outputs()->where('status', Shortlist::REJECTED)->count())->toBe(2);
    $rejected = $this->request->outputs()->where('status', Shortlist::REJECTED)->orderBy('id')->get();
    $kept = $this->request->outputs()->where('status', Shortlist::SHORTLISTED)->get();

    // Too soon: a day by default.
    expect($this->retention->prune())->toBe(0)->and(Storage::disk()->exists($rejected[0]->path))->toBeTrue();

    $this->travel(2)->days();
    expect($this->retention->prune())->toBe(2);

    foreach ($rejected as $output) {
        $output->refresh();
        expect($output->isRemoved())->toBeTrue()->and($output->reason)->toContain('the best 1 were chosen')
            ->and(Storage::disk()->exists($output->path))->toBeFalse()->and(Storage::disk()->exists($output->thumb_path))->toBeFalse();
    }
    foreach ($kept as $output) {
        expect($output->fresh()->isRemoved())->toBeFalse()->and(Storage::disk()->exists($output->path))->toBeTrue();
    }
    expect(Activity::where('event', 'rejects_removed')->sole()->description)->toBe('Removed the files of 2 rejected outputs on Canadian Protein, kept 1 day after review')
        ->and($this->retention->prune())->toBe(0);

    // The page still shows what was rejected and why, without the picture; the file is gone; nothing can be made from it.
    $this->get(route('requests.show', [$this->brand, $this->request]))->assertOk()->assertSee('File deleted after a day')->assertSee('the best 1 were chosen');
    $this->get($rejected[0]->thumbUrl())->assertNotFound();
    expect(app(Changes::class)->cannotChange($rejected[0]))->toContain('its file has been deleted');
});

it('keeps a rejected image while a changed version made from it is still there, and follows each brand\'s own setting', function () {
    $rejected = $this->request->outputs()->where('status', Shortlist::REJECTED)->orderBy('id')->first();
    $version = Output::create([...$rejected->only(['request_id', 'generation_id', 'ratio', 'kind', 'path', 'thumb_path', 'mime', 'width', 'height', 'size']), 'source_id' => $rejected->id, 'position' => 9, 'status' => Shortlist::SHORTLISTED]);

    $this->travel(2)->days();
    expect($this->retention->prune())->toBe(1)->and($rejected->fresh()->isRemoved())->toBeFalse();

    // Once the version is gone too, the original follows.
    $version->update(['status' => Shortlist::REJECTED, 'reason' => 'Scored 50, under the minimum of 70']);
    $this->travel(2)->days();
    expect($this->retention->prune())->toBe(2)->and($rejected->fresh()->isRemoved())->toBeTrue();

    // A brand that keeps rejects longer.
    app(Settings::class)->set('retention.rejects_days', 30, SettingScope::Brand, $this->brand->id, $this->admin);
    $this->get(route('requests.show', [$this->brand, $this->request]))->assertOk()->assertSee('kept 30 days in case the reviewer was wrong')->assertSee('File deleted after 30 days');
    expect($this->retention->cutoff($this->brand)->isBefore(now()->subDays(29)))->toBeTrue();
});

it('runs nightly from the scheduler, and from the command', function () {
    expect(array_column(app(Scheduler::class)->tasks(), 'command'))->toContain('outputs:prune-rejects');

    $this->artisan('outputs:prune-rejects')->expectsOutputToContain('Nothing was due')->assertSuccessful();
    $this->travel(2)->days();
    $this->artisan('outputs:prune-rejects')->expectsOutputToContain('Removed the files of 2 rejected outputs')->assertSuccessful();
    $this->get(route('settings'))->assertOk();
    $this->get(route('area-settings', [$this->brand, 'review']))->assertOk()->assertSee('Keep rejected outputs for');
});

<?php

use App\Auth\Role;
use App\Budgets\Budgets;
use App\Images\ImageFiles;
use App\Images\ImageGenerator;
use App\Images\ImageModels;
use App\Jobs\GenerateImage;
use App\Models\Connection;
use App\Models\CreativeRequest;
use App\Models\Generation;
use App\Models\ImageModel;
use App\Models\Output;
use App\Models\Placement;
use App\Models\Spend;
use App\Platforms\PlatformLibrary;
use App\Prompts\PromptType;
use App\Prompts\Starters;
use App\Requests\RequestReferences;
use App\Requests\Requests;
use App\Requests\Runs;
use App\Review\Reviewer;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
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
    $this->requests = app(Requests::class);

    $ids = Placement::whereIn('key', ['feed-portrait', 'feed-square', 'stories'])->whereHas('platform', fn ($q) => $q->where('key', 'instagram'))->pluck('id')->map(fn ($i) => (string) $i)->all();
    fakeLlm([['submit_plan', ['title' => 'Whey weekend', 'summary' => 'S', 'image_prompt' => 'A kitchen counter in morning light.', 'ratio_notes' => ['9:16' => 'Leave headroom.'], 'image_model' => 'gemini/gemini-nano-banana-2.1']]]);
    $this->request = $this->requests->create($this->brand, ['brief' => 'Marketing image for a sale on whey protein isolate, 20% off this weekend.', 'languages' => ['en'], 'output' => 'image', 'copy' => 'all', 'placement_ids' => $ids], $this->admin);
    $this->requests->plan($this->request);
    $this->request->refresh();

    // A 64 × 80 PNG, as if the model made it.
    $im = imagecreatetruecolor(64, 80);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 180, 160));
    ob_start();
    imagepng($im);
    $this->png = (string) ob_get_clean();
    $this->image = fn () => ['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($this->png)]]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 1120], 'modelVersion' => 'nb-2.1'];
});

it('runs a confirmed request: one generation per shape, one job per image, the files on the disk, the cost recorded, the request done', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(($this->image)())]);

    $this->requests->confirm($this->request, $this->admin);
    $request = $this->request->fresh();

    expect($request->status)->toBe(CreativeRequest::DONE)->and($request->batch_id)->not->toBeNull()->and($request->finished_at)->not->toBeNull()
        ->and($request->generations)->toHaveCount(3)->and($request->generations->pluck('ratio')->all())->toBe(['1:1', '4:5', '9:16'])
        ->and($request->generations->every(fn (Generation $g) => $g->status === Generation::DONE && $g->done === 2 && $g->failed === 0))->toBeTrue()
        ->and($request->generations[2]->prompt)->toBe("A kitchen counter in morning light.\n\nLeave headroom.\n\n".Starters::body(PromptType::Image))
        ->and($request->generations[0]->prompt)->not->toContain('headroom')
        ->and($request->outputs)->toHaveCount(6);

    $output = $request->outputs->first();
    expect($output->width)->toBe(64)->and($output->mime)->toBe('image/png')->and($output->served_by)->toBe('nb-2.1')->and($output->position)->toBe(1)
        ->and(Storage::disk()->exists($output->path))->toBeTrue()->and(Storage::disk()->exists($output->thumb_path))->toBeTrue()
        ->and($output->downloadName())->toBe('whey-weekend-1x1-1.png')
        // 100 prompt tokens at $1.50 and 1,120 output tokens at $30, per million.
        ->and(round($output->cost, 5))->toBe(0.03375)
        ->and(round($request->generations[0]->cost, 4))->toBe(0.0675)
        ->and(Spend::where('request_id', $request->id)->where('kind', 'image')->count())->toBe(6)
        // Six images plus the planning call.
        ->and($request->actual_cost)->toEqualWithDelta(6 * 0.03375 + (float) Spend::where('kind', 'plan')->sum('amount'), 0.0001);

    Http::assertSentCount(6);
    Http::assertSent(fn ($r) => $r['generationConfig']['imageConfig'] === ['aspectRatio' => '9:16', 'imageSize' => '1K']);

    // The files are served to people who may see the request, the full one as a download.
    $this->get($output->thumbUrl())->assertOk()->assertHeader('Content-Type', 'image/webp');
    $this->get($output->url().'?download=1')->assertOk()->assertHeader('Content-Type', 'image/png')->assertDownload('whey-weekend-1x1-1.png');
    $this->get(route('requests.show', [$this->brand, $request]))->assertOk()->assertSee('The images')->assertSee('6 images')->assertSee('1:1')->assertDontSee('Confirm and run');

    // The owner is told, and opening the page just now counted as seeing it.
    $notice = $this->admin->notifications()->where('data->event', 'done')->sole();
    expect($notice->data['title'])->toBe('"Whey weekend" is ready')->and($notice->data['body'])->toBe('6 of 6 outputs passed review and are on the shortlist, for $'.Budgets::money((float) $request->actual_cost).'.')
        ->and($notice->read_at)->not->toBeNull();
});

it('keeps going when one image is refused, tries again when the provider is busy, and stops a shape when the key is bad', function () {
    app(Settings::class)->set('generation.retries', 1, SettingScope::Platform, 0, $this->admin);
    $blocked = ['candidates' => [['content' => ['parts' => [['text' => 'Not that.']]], 'finishReason' => 'IMAGE_SAFETY']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]];
    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
        ->push(($this->image)())               // 1:1 #1
        ->push($blocked)                       // 1:1 #2 refused
        ->push(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429)->push(($this->image)())  // 4:5 #1 busy then fine
        ->push(($this->image)())               // 4:5 #2
        ->push(['error' => ['status' => 'PERMISSION_DENIED']], 403),  // 9:16 #1: the key is refused, so #2 isn't tried
    ]);

    $this->requests->confirm($this->request, $this->admin);
    $request = $this->request->fresh();
    [$square, $portrait, $story] = $request->generations;

    expect($request->status)->toBe(CreativeRequest::DONE)->and($request->outputs)->toHaveCount(3)
        ->and($square->status)->toBe(Generation::DONE)->and($square->done)->toBe(1)->and($square->failed)->toBe(1)->and($square->error)->toContain('wouldn\'t make this image. Not that.')
        ->and($portrait->status)->toBe(Generation::DONE)->and($portrait->done)->toBe(2)->and($portrait->failed)->toBe(0)
        ->and($story->status)->toBe(Generation::FAILED)->and($story->done)->toBe(0)->and($story->error)->toContain('refused the API key');
    Http::assertSentCount(6);
    Sleep::assertSleptTimes(1);

    $page = $this->get(route('requests.show', [$this->brand, $request]))->assertOk();
    $page->assertSee('1 image failed')->assertSee('1 of 2 made')->assertSee('refused the API key');
});

it('fails the request when nothing comes back, and reserves the estimate only while it runs', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503)]);
    app(Settings::class)->set('generation.retries', 0, SettingScope::Platform, 0, $this->admin);

    $this->requests->confirm($this->request, $this->admin);
    $request = $this->request->fresh();

    expect($request->status)->toBe(CreativeRequest::FAILED)->and($request->error)->toContain('Google returned an error (UNAVAILABLE)')->and($request->outputs)->toHaveCount(0)
        // Nothing reserved any more: only the planning call counts.
        ->and(app(Budgets::class)->monthSpend())->toEqualWithDelta((float) Spend::where('kind', 'plan')->sum('amount'), 0.00001)
        ->and(Spend::where('kind', 'image')->count())->toBe(0)
        ->and($this->admin->notifications()->where('data->event', 'done')->sole()->data['title'])->toBe('"Whey weekend" failed');
});

it('sends the style references with the prompt, not the product shots, and only as many as the model takes', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(($this->image)())]);
    $refs = app(RequestReferences::class);
    $refs->add($this->request, UploadedFile::fake()->image('look.jpg', 50, 50), 'style');
    $refs->add($this->request, UploadedFile::fake()->image('tub.png', 50, 50), 'product');
    ImageModel::findByKey('gemini/gemini-nano-banana-2.1')->update(['max_references' => 1]);

    $this->requests->confirm($this->request, $this->admin);

    Http::assertSent(function ($r) {
        $parts = $r['contents'][0]['parts'];

        return count($parts) === 3 && $parts[1]['text'] === 'Reference image 1: the look and mood wanted, not the subject' && isset($parts[2]['inline_data']);
    });
});

it('stops a run: the queued jobs are dropped and the images already made are kept', function () {
    Bus::fake([GenerateImage::class]);
    $this->requests->confirm($this->request, $this->admin);
    $request = $this->request->fresh();
    expect($request->status)->toBe(CreativeRequest::RUNNING)->and($request->generations)->toHaveCount(3);
    Bus::assertBatched(fn ($batch) => $batch->jobs->filter(fn ($job) => $job instanceof GenerateImage)->count() === 6);

    $this->requests->cancel($request, $this->admin);
    expect($request->fresh()->status)->toBe(CreativeRequest::CANCELLED)->and($request->generations()->where('status', Generation::FAILED)->count())->toBe(3);

    // A job that runs after the stop does nothing.
    Http::fake();
    (new GenerateImage($request->generations->first(), 1))->handle(app(Runs::class), app(ImageGenerator::class), app(ImageFiles::class), app(Budgets::class), app(Settings::class));
    Http::assertNothingSent();
    expect(Output::count())->toBe(0);
});

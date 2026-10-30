<?php

use App\Auth\Role;
use App\BrandKit\BrandKits;
use App\BrandKit\Kind;
use App\Composer\Renderer;
use App\Images\ImageModels;
use App\Llm\LlmRequest;
use App\Models\Connection;
use App\Models\CreativeRequest;
use App\Models\Output;
use App\Models\Placement;
use App\Models\Product;
use App\Models\Review;
use App\Models\Spend;
use App\Platforms\PlatformLibrary;
use App\Requests\Requests;
use App\Review\ReviewPrompt;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeRemover;
use Tests\Support\FakeRenderer;

beforeEach(function () {
    Storage::fake();
    app()->instance(Renderer::class, $this->renderer = new FakeRenderer);
    $this->admin = signInAs(Role::Admin);
    $this->brand = makeBrand(['name' => 'Canadian Protein']);
    app(PlatformLibrary::class)->addStarters($this->admin);
    app(ImageModels::class)->addStarters($this->admin);
    Connection::factory()->create(['plugin' => 'gemini', 'name' => 'Gemini', 'brand_id' => null, 'config' => [], 'secrets' => ['api_key' => 'k'], 'status' => Connection::STATUS_CONNECTED]);
    app(Settings::class)->set('generation.per_ratio', 4, SettingScope::Platform, 0, $this->admin);
    app(Settings::class)->set('review.deliver', 2, SettingScope::Platform, 0, $this->admin);
    $this->feed = Placement::where('key', 'feed-square')->whereHas('platform', fn ($q) => $q->where('key', 'instagram'))->sole();
    $this->requests = app(Requests::class);
    $this->llm = fakeLlm([['submit_plan', ['title' => 'Whey weekend', 'summary' => 'A weekend sale.', 'offer' => ['headline' => '20% off'], 'image_prompt' => 'A counter.', 'image_model' => 'gemini/gemini-nano-banana-2.1']]]);
    $im = imagecreatetruecolor(64, 64);
    ob_start();
    imagepng($im);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode((string) ob_get_clean())]]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 1120]])]);
});

function verdict(int $brief, int $brand, int $technical, int $platform, array $fails = [], string $reason = 'Fine.'): array
{
    return [['submit_review', ['scores' => [
        ['criterion' => 'brief', 'score' => $brief], ['criterion' => 'brand', 'score' => $brand], ['criterion' => 'technical', 'score' => $technical, 'note' => 'ok'], ['criterion' => 'platform', 'score' => $platform],
    ], 'hard_fails' => $fails, 'reason' => $reason]]];
}

it('reviews every image with the picture, scores it in code from the weights, and shortlists the best that pass', function () {
    $request = $this->requests->create($this->brand, ['brief' => 'Marketing image for a sale on whey protein isolate, 20% off this weekend.', 'languages' => ['en'], 'output' => 'image', 'copy' => 'none', 'placement_ids' => [(string) $this->feed->id]], $this->admin);
    $this->requests->plan($request);
    $this->llm->push(
        verdict(90, 90, 90, 90, reason: 'Clean and on brand.'),          // 90
        verdict(80, 60, 100, 50, reason: 'A bit dark.'),                  // 30*80+25*60+25*100+20*50 = 7400/100 = 74
        verdict(95, 95, 95, 95, ['distorted_people'], 'The hand is wrong.'), // 95 but a hard fail
        verdict(60, 60, 60, 60, reason: 'Generic.'),                      // 60, under 70
    );

    $this->requests->confirm($request->fresh(), $this->admin);
    $request = $request->fresh();
    $outputs = $request->outputs()->orderBy('position')->get();

    expect($request->status)->toBe(CreativeRequest::DONE)->and(Review::count())->toBe(4)
        ->and($outputs->pluck('score')->all())->toBe([90, 74, 95, 60])
        ->and($outputs->pluck('status')->all())->toBe(['shortlisted', 'shortlisted', 'rejected', 'rejected'])
        ->and($outputs[2]->reason)->toBe('A person has distorted hands, face or body The hand is wrong.')
        ->and($outputs[3]->reason)->toBe('Scored 60, under the minimum of 70. Generic.')
        ->and($outputs[0]->review->scores[2])->toBe(['criterion' => 'technical', 'label' => 'Technical', 'weight' => 25, 'score' => 90, 'note' => 'ok'])
        ->and(Spend::where('request_id', $request->id)->where('kind', 'review')->count())->toBe(4)
        ->and(Activity::where('log_name', 'requests')->latest('id')->first()->description)->toContain('2 of 4 outputs delivered');

    // The reviewer saw the brief, the shape and the picture as a JPEG, and was told the criteria and the hard fails.
    $review = collect($this->llm->requests)->first(fn (LlmRequest $r) => str_contains($r->system, 'You review marketing creative'));
    expect($review->model)->toBe('claude-opus-5-5')->and($review->system)->toContain('brief (Matches the brief, weight 30)', 'distorted_people:', 'Canadian Protein')
        ->and($review->messages[0]['content'][0]['text'])->toContain('The brief:', 'Shape: 1:1')
        ->and($review->messages[0]['content'][2]['type'])->toBe('image')->and($review->messages[0]['content'][2]['media_type'])->toBe('image/jpeg')
        ->and(getimagesizefromstring(base64_decode($review->messages[0]['content'][2]['data']))['mime'])->toBe('image/jpeg');

    $page = $this->get(route('requests.show', [$this->brand, $request]))->assertOk();
    $page->assertSee('Shortlisted')->assertSee('Clean and on brand.')->assertSee('Rejected: 2 images')->assertSee('The hand is wrong.')->assertSee('under the minimum of 70');
});

it('reviews finished ads with the real pack shot beside them, rejects a failed check without asking the reviewer, and honours the request\'s own number to deliver', function () {
    app(Settings::class)->set('generation.per_ratio', 2, SettingScope::Platform, 0, $this->admin);
    $kits = app(BrandKits::class);
    $whey = Product::factory()->create(['brand_id' => $this->brand->id, 'title' => 'Whey Protein Isolate']);
    $vanilla = $whey->variants()->create(['external_id' => 'v1', 'title' => '1 kg / Vanilla', 'price' => '49.99', 'available' => true, 'options' => ['Size' => '1 kg'], 'position' => 1]);
    $kits->addFile($this->brand, Kind::PackShot, UploadedFile::fake()->image('tub.png', 300, 500), $this->admin, ['product_variant_id' => $vanilla->id]);
    $this->feed->update(['max_image_kb' => 1]); // every ad will be over the limit: rejected on the check, never reviewed
    $story = Placement::where('key', 'stories')->whereHas('platform', fn ($q) => $q->where('key', 'instagram'))->sole();

    $request = $this->requests->create($this->brand, ['brief' => 'Marketing image for a sale on whey protein isolate, 20% off this weekend.', 'languages' => ['en'], 'output' => 'ad', 'copy' => 'none', 'deliver' => '1', 'product_variant_id' => (string) $vanilla->id, 'placement_ids' => [(string) $this->feed->id, (string) $story->id]], $this->admin);
    $this->requests->plan($request);
    $ad = fn (int $product, array $fails = []) => [['submit_review', ['scores' => [['criterion' => 'brief', 'score' => 85], ['criterion' => 'brand', 'score' => 85], ['criterion' => 'product', 'score' => $product], ['criterion' => 'text', 'score' => 90], ['criterion' => 'technical', 'score' => 90]], 'hard_fails' => $fails, 'reason' => 'ok']]];
    // Four images (two shapes × two) and two story ads are reviewed, in whatever order the jobs run; the two feed ads are never sent.
    $adsSeen = 0;
    $reply = function (LlmRequest $r) use (&$adsSeen, $ad) {
        if (! str_contains($r->system, 'a finished ad')) {
            return verdict(80, 80, 80, 80);
        }

        return ++$adsSeen === 1 ? $ad(95) : $ad(30, ['altered_label']);
    };
    $this->llm->push($reply, $reply, $reply, $reply, $reply, $reply);

    $this->requests->confirm($request->fresh(), $this->admin);
    $request = $request->fresh();

    $feedAds = Output::where('kind', Output::AD)->where('placement_id', $this->feed->id)->get();
    $storyAds = Output::where('kind', Output::AD)->where('placement_id', $story->id)->orderBy('id')->get();
    expect($request->status)->toBe(CreativeRequest::DONE)
        ->and($feedAds->pluck('status')->unique()->all())->toBe(['rejected'])->and($feedAds[0]->reason)->toContain('Failed a check:')->and($feedAds[0]->review)->toBeNull()
        ->and($storyAds->pluck('status')->sort()->values()->all())->toBe(['rejected', 'shortlisted'])->and($storyAds->firstWhere('status', 'rejected')->reason)->toContain('label or packaging differs')
        // Deliver 1 per shape for the clean images too.
        ->and(Output::where('kind', Output::IMAGE)->where('status', 'shortlisted')->count())->toBe(2)
        ->and(Output::where('kind', Output::IMAGE)->where('status', 'rejected')->first()->reason)->toContain('the best 1 were chosen');

    $adReview = collect($this->llm->requests)->first(fn (LlmRequest $r) => str_contains($r->system, 'a finished ad'));
    expect($adReview->system)->toContain('product (Product accuracy, weight 20)')
        ->and($adReview->messages[0]['content'][0]['text'])->toContain('Placement: Instagram · Stories', 'Checks already done')
        ->and(collect($adReview->messages[0]['content'])->where('type', 'image'))->toHaveCount(2);
});

it('keeps an output whose review failed out of the shortlist, with the reason, without stopping the run', function () {
    app(Settings::class)->set('generation.per_ratio', 2, SettingScope::Platform, 0, $this->admin);
    $request = $this->requests->create($this->brand, ['brief' => 'Marketing image for a sale on whey protein isolate, 20% off this weekend.', 'languages' => ['en'], 'output' => 'image', 'copy' => 'none', 'placement_ids' => [(string) $this->feed->id]], $this->admin);
    $this->requests->plan($request);
    $this->llm->push(verdict(90, 90, 90, 90), 'I would rather not.', 'Still no.');

    $this->requests->confirm($request->fresh(), $this->admin);
    $outputs = $request->fresh()->outputs()->orderBy('position')->get();

    expect($outputs[0]->status)->toBe('shortlisted')->and($outputs[1]->status)->toBe('rejected')->and($outputs[1]->score)->toBeNull()
        ->and($outputs[1]->reason)->toBe('Not reviewed: The review model gave no scores.')->and($outputs[1]->review->status)->toBe(Review::FAILED);
    $this->get(route('requests.show', [$this->brand, $request]))->assertSee('Not reviewed: The review model gave no scores.');
    expect(app(ReviewPrompt::class)->resolve($this->brand, $outputs[0])->criteria())->toHaveKeys(['brief', 'brand', 'technical', 'platform']);
});

it('shows the reviewer the cut-out of the store photo beside an ad when the kit has no pack shot', function () {
    app(Settings::class)->set('generation.per_ratio', 1, SettingScope::Platform, 0, $this->admin);
    $whey = Product::factory()->create(['brand_id' => $this->brand->id, 'title' => 'Whey Protein Isolate']);
    $vanilla = $whey->variants()->create(['external_id' => 'v1', 'title' => '1 kg / Vanilla', 'price' => '49.99', 'available' => true, 'options' => ['Size' => '1 kg'], 'position' => 1]);
    $photo = $whey->images()->create(['external_id' => 'img', 'url' => 'https://cdn.example/whey.png', 'position' => 0, 'cutout_path' => 'catalogue/1/cutouts/1.png']);
    $vanilla->update(['product_image_id' => $photo->id]);
    Storage::disk()->put('catalogue/1/cutouts/1.png', FakeRemover::transparentPng());

    $request = $this->requests->create($this->brand, ['brief' => 'Marketing image for a sale on whey protein isolate.', 'languages' => ['en'], 'output' => 'ad', 'copy' => 'none', 'product_variant_id' => (string) $vanilla->id, 'placement_ids' => [(string) $this->feed->id]], $this->admin);
    $this->requests->plan($request);
    $reply = fn (LlmRequest $r) => str_contains($r->system, 'a finished ad')
        ? [['submit_review', ['scores' => [['criterion' => 'brief', 'score' => 85], ['criterion' => 'brand', 'score' => 85], ['criterion' => 'product', 'score' => 90], ['criterion' => 'text', 'score' => 90], ['criterion' => 'technical', 'score' => 90]], 'hard_fails' => [], 'reason' => 'ok']]]
        : verdict(80, 80, 80, 80);
    $this->llm->push($reply, $reply);

    $this->requests->confirm($request->fresh(), $this->admin);

    $adReview = collect($this->llm->requests)->first(fn (LlmRequest $r) => str_contains($r->system, 'a finished ad'));
    $texts = collect($adReview->messages[0]['content'])->pluck('text')->filter()->values()->all();
    expect($texts)->toContain('The real product, for comparing the label and packaging:')
        ->and(collect($adReview->messages[0]['content'])->where('type', 'image')->count())->toBe(2)
        ->and(Output::where('kind', Output::AD)->sole()->passedChecks())->toBeTrue();
});

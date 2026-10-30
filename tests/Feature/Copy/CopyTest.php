<?php

use App\Auth\Role;
use App\Composer\Renderer;
use App\Copy\CopyWriter;
use App\Images\ImageModels;
use App\Llm\LlmRequest;
use App\Models\Connection;
use App\Models\Copy;
use App\Models\CreativeRequest;
use App\Models\Output;
use App\Models\Placement;
use App\Models\Spend;
use App\Platforms\PlatformLibrary;
use App\Requests\Requests;
use App\Review\Reviewer;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeRenderer;
use Tests\Support\FakeReviewer;

beforeEach(function () {
    Storage::fake();
    app()->instance(Reviewer::class, new FakeReviewer);
    app()->instance(Renderer::class, $this->renderer = new FakeRenderer);
    $this->admin = signInAs(Role::Admin);
    $this->brand = makeBrand(['name' => 'Canadian Protein']);
    app(PlatformLibrary::class)->addStarters($this->admin);
    app(ImageModels::class)->addStarters($this->admin);
    Connection::factory()->create(['plugin' => 'gemini', 'name' => 'Gemini', 'brand_id' => null, 'config' => [], 'secrets' => ['api_key' => 'k'], 'status' => Connection::STATUS_CONNECTED]);
    app(Settings::class)->set('generation.per_ratio', 1, SettingScope::Platform, 0, $this->admin);
    app(Settings::class)->set('copy.versions', 2, SettingScope::Platform, 0, $this->admin);
    $this->feed = Placement::where('key', 'feed-portrait')->whereHas('platform', fn ($q) => $q->where('key', 'meta-ads'))->sole();
    $this->requests = app(Requests::class);
    $this->llm = fakeLlm([['submit_plan', ['title' => 'Whey weekend', 'summary' => 'A weekend sale.', 'offer' => ['headline' => '20% off whey isolate', 'details' => 'This weekend'], 'cta' => 'Shop now', 'image_prompt' => 'A counter.', 'image_model' => 'gemini/gemini-nano-banana-2.1']]]);
    $im = imagecreatetruecolor(64, 80);
    ob_start();
    imagepng($im);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode((string) ob_get_clean())]]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 1120]])]);
});

function copyVersion(string $primary, string $headline = 'Fuel the weekend', string $description = 'Clean whey, fast delivery.'): array
{
    return ['primary_text' => $primary, 'headline' => $headline, 'description' => $description];
}

it('writes copy per placement and language to its limits, counts every piece, asks for a rewrite when over, and records the spend', function () {
    $request = $this->requests->create($this->brand, ['brief' => 'Marketing image for a sale on whey protein isolate, 20% off this weekend.', 'languages' => ['en', 'fr'], 'output' => 'image', 'copy' => 'ad', 'placement_ids' => [(string) $this->feed->id]], $this->admin);
    $this->requests->plan($request);

    $long = str_repeat('Whey for days. ', 10); // 150 characters, over the 125 primary text limit
    $this->llm->push(
        // English: one piece over, then shortened.
        [['submit_copy', ['versions' => [copyVersion($long), copyVersion('Twenty percent off whey isolate this weekend only.')]]]],
        [['submit_copy', ['versions' => [copyVersion('Whey for days, 20% off this weekend.'), copyVersion('Twenty percent off whey isolate this weekend only.')]]]],
        // French: fine first time.
        [['submit_copy', ['versions' => [copyVersion('Vingt pour cent de rabais sur l\'isolat ce week-end.', 'Carburez la fin de semaine', 'Livraison rapide.'), copyVersion('Isolat de whey, 20 % de rabais.', 'En fin de semaine', 'Pur et rapide.')]]]],
    );

    $this->requests->confirm($request->fresh(), $this->admin);
    $request = $request->fresh();

    expect($request->status)->toBe(CreativeRequest::DONE)->and($request->copies)->toHaveCount(2);

    $en = $request->copies->firstWhere('language', 'en');
    $fr = $request->copies->firstWhere('language', 'fr');
    expect($en->status)->toBe(Copy::DONE)->and($en->versions)->toHaveCount(2)->and($en->fits())->toBeTrue()
        ->and($en->versions[0]['primary_text'])->toBe('Whey for days, 20% off this weekend.')
        ->and($en->versions[0]['checks']['primary_text'])->toBe(['count' => 36, 'limit' => 125, 'ok' => true])
        ->and($en->versions[0]['checks'])->toHaveKeys(['primary_text', 'headline', 'description'])->not->toHaveKey('caption')
        ->and($fr->versions[0]['headline'])->toBe('Carburez la fin de semaine')
        ->and(Spend::where('request_id', $request->id)->where('kind', 'copy')->count())->toBe(2)
        ->and((float) $en->cost)->toBeGreaterThan(0);

    // The writer was told the voice, the plan, the placement, the limits and the language; the rewrite named what was over.
    $calls = collect($this->llm->requests)->filter(fn (LlmRequest $r) => str_contains($r->system, 'You write marketing copy'));
    expect($calls)->toHaveCount(3)
        ->and($calls->first()->system)->toContain('in English', 'Canadian Protein')
        ->and($calls->first()->messages[0]['content'])->toContain('Write 2 versions', 'Primary text (primary_text): up to 125 characters', 'Meta ads · Feed (4:5)', 'The offer: 20% off whey isolate')
        ->and($calls->first()->tools[0]->schema['properties']['versions']['items']['required'])->toBe(['primary_text', 'headline', 'description'])
        ->and($calls->skip(1)->first()->messages[2]['content'][0]['content'])->toContain('version 1 primary_text is 149 (limit 125)')
        ->and($calls->last()->system)->toContain('in French');

    $this->get(route('requests.show', [$this->brand, $request]))->assertOk()->assertSee('Copy')->assertSee('Meta ads · Feed (4:5)')->assertSee('36/125')->assertSee('Carburez la fin de semaine');
});

it('shows a version as over the limit after the allowed rewrites, and writes captions only when that is what was asked', function () {
    app(Settings::class)->set('copy.rewrites', 0, SettingScope::Platform, 0, $this->admin);
    $this->feed->update(['copy_limits' => ['primary_text' => 125, 'caption' => 100, 'hashtags' => 10]]);
    $request = $this->requests->create($this->brand, ['brief' => 'Marketing image for a sale on whey protein isolate, 20% off this weekend.', 'languages' => ['en'], 'output' => 'image', 'copy' => 'captions', 'placement_ids' => [(string) $this->feed->id]], $this->admin);
    $this->requests->plan($request);
    $this->llm->push([['submit_copy', ['versions' => [['caption' => 'Big weekend.', 'hashtags' => ['whey', '#protein', 'isolate', 'sale', 'gym', 'fuel', 'weekend', 'deal', 'cp', 'strong', 'eleven']], ['caption' => 'Fine.', 'hashtags' => ['#whey']]]]]]);

    $this->requests->confirm($request->fresh(), $this->admin);
    $copy = $request->fresh()->copies->sole();

    expect($copy->fits())->toBeFalse()
        ->and($copy->versions[0]['hashtags'][0])->toBe('#whey')
        ->and($copy->versions[0]['checks']['hashtags'])->toBe(['count' => 11, 'limit' => 10, 'ok' => false])
        ->and($copy->versions[0]['checks'])->toHaveKeys(['caption', 'hashtags'])->not->toHaveKey('primary_text')
        ->and($copy->versions[1]['checks']['hashtags']['ok'])->toBeTrue();
    $this->get(route('requests.show', [$this->brand, $request]))->assertSee('11/10');
});

it('writes the words for French finished ads, and composes them once those words are in', function () {
    $request = $this->requests->create($this->brand, ['brief' => 'Marketing image for a sale on whey protein isolate, 20% off this weekend.', 'languages' => ['fr', 'en'], 'output' => 'ad', 'copy' => 'none', 'placement_ids' => [(string) $this->feed->id]], $this->admin);
    $this->requests->plan($request);
    $this->llm->push([['submit_copy', ['versions' => [['headline' => '20 % de rabais sur l\'isolat', 'subline' => 'Cette fin de semaine seulement', 'cta' => 'Magasinez']]]]]);

    $this->requests->confirm($request->fresh(), $this->admin);
    $request = $request->fresh();

    $ads = Output::where('kind', Output::AD)->get();
    expect($request->status)->toBe(CreativeRequest::DONE)->and($ads->pluck('language')->sort()->values()->all())->toBe(['en', 'fr'])
        ->and($request->copies->sole()->kind)->toBe(Copy::AD)->and($request->copies->sole()->adText()['cta'])->toBe('Magasinez');

    $renders = collect($this->renderer->renders);
    expect($renders->filter(fn ($r) => str_contains($r['html'], '20 % de rabais'))->count())->toBe(1)
        ->and($renders->filter(fn ($r) => str_contains($r['html'], '20% off whey isolate'))->count())->toBe(1)
        ->and($ads->firstWhere('language', 'fr')->downloadName())->toBe('whey-weekend-meta-ads-feed-45-fr-1.png');
    $this->get(route('requests.show', [$this->brand, $request]))->assertSee('Words on the finished ads · French')->assertSee('Magasinez');
});

it('records a writer that never calls the tool as failed copy, without stopping the run', function () {
    $request = $this->requests->create($this->brand, ['brief' => 'Marketing image for a sale on whey protein isolate, 20% off this weekend.', 'languages' => ['en'], 'output' => 'image', 'copy' => 'all', 'placement_ids' => [(string) $this->feed->id]], $this->admin);
    $this->requests->plan($request);
    $this->llm->push('Here is some copy...', 'Still talking.', 'And more.', 'Done?');

    $this->requests->confirm($request->fresh(), $this->admin);
    $request = $request->fresh();

    expect($request->status)->toBe(CreativeRequest::DONE)->and(Output::where('kind', Output::IMAGE)->count())->toBe(1)
        ->and($request->copies->sole()->status)->toBe(Copy::FAILED)->and($request->copies->sole()->error)->toBe('The text model sent no copy.');
    expect(CopyWriter::limitsFor($request, Placement::where('key', 'display-191')->first() ?? $this->feed))->toBeArray();
});

<?php

use App\Auth\Role;
use App\Composer\Renderer;
use App\Images\ImageModels;
use App\Jobs\TryPrompt;
use App\Livewire\Prompts\PromptsPage;
use App\Llm\LlmRequest;
use App\Models\Connection;
use App\Models\Placement;
use App\Models\PromptRevision;
use App\Models\Spend;
use App\Platforms\PlatformLibrary;
use App\Prompts\Prompts;
use App\Prompts\PromptTrials;
use App\Prompts\PromptType;
use App\Prompts\Starters;
use App\Requests\Requests;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeRenderer;

beforeEach(function () {
    Storage::fake();
    app()->instance(Renderer::class, new FakeRenderer);
    $this->admin = signInAs(Role::Admin, ['name' => 'Ada Admin']);
    $this->brand = makeBrand(['name' => 'Canadian Protein']);
    app(PlatformLibrary::class)->addStarters($this->admin);
    app(ImageModels::class)->addStarters($this->admin);
    Connection::factory()->create(['plugin' => 'gemini', 'name' => 'Gemini', 'brand_id' => null, 'config' => [], 'secrets' => ['api_key' => 'k'], 'status' => Connection::STATUS_CONNECTED]);
    $im = imagecreatetruecolor(64, 64);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 180, 160));
    ob_start();
    imagepng($im);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode((string) ob_get_clean())]]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 1120]])]);
    $this->prompts = app(Prompts::class);
});

it('tries the planner, copy and changes prompts on a sample without saving, shows what came back, and records the spend against the day\'s test budget', function () {
    $llm = fakeLlm(function (LlmRequest $r) {
        // The edited text, not the saved one, with the placeholders filled and the sample brief as the request.
        expect($r->system)->toContain('Always plan a morning scene.', "planner for Canadian Protein's")->not->toContain('{brand}')
            ->and($r->messages[0]['content'])->toContain("Brief:\nA sale on creatine, 15% off.", 'Images per shape: 1', '1:1:');

        return [['submit_plan', ['title' => 'Creatine sale', 'summary' => 'A short sale on creatine.', 'offer' => ['headline' => '15% off creatine', 'details' => 'This week'], 'image_prompt' => 'A tub of creatine on a counter in morning light.', 'image_model' => 'gemini/gemini-nano-banana-2.1', 'model_reason' => 'Fast and cheap.']]];
    });

    $page = Livewire::test(PromptsPage::class, ['brand' => $this->brand])->assertSee('Try it before saving')->assertSee('Your test budget today: $0.00 of $2.00 used.')
        ->assertSet('sampleBrief', PromptTrials::SAMPLE_BRIEF)
        ->set('body', Starters::body(PromptType::Planner)."\nAlways plan a morning scene.")
        ->set('sampleBrief', 'short')->call('tryIt')->assertHasErrors('sampleBrief')
        ->set('sampleBrief', 'A sale on creatine, 15% off.')->call('tryIt')->assertHasNoErrors()
        ->assertSee('What came back')->assertSee('Creatine sale')->assertSee('15% off creatine (This week)')->assertSee('A tub of creatine on a counter in morning light.')->assertSee('Nano Banana')->assertSee('Fast and cheap.')->assertSee('No product was chosen')->assertSee('Nothing was saved.');

    expect($this->prompts->for($this->brand, PromptType::Planner)->current->number)->toBe(1)
        ->and(PromptRevision::count())->toBe(1)   // the planner's starter, nothing more
        ->and(Spend::where('kind', 'try')->sole()->only(['brand_id', 'user_id', 'provider', 'note']))->toBe(['brand_id' => $this->brand->id, 'user_id' => $this->admin->id, 'provider' => 'anthropic', 'note' => 'Tried the Planner prompt'])
        ->and(app(PromptTrials::class)->budget($this->admin)['spent'])->toBeGreaterThan(0);
    $page->assertDontSee('$0.00 of $2.00 used');

    // The planner may ask instead; the questions are shown with their choices.
    $llm->push([['ask_questions', ['questions' => [['id' => 'size', 'question' => 'Which size?', 'type' => 'choice', 'options' => [['value' => '1kg', 'label' => '1 kg'], ['value' => '2kg', 'label' => '2 kg']]]]]]]);
    $page->call('tryIt')->assertHasNoErrors()->assertSee('The planner would ask first:')->assertSee('Which size?')->assertSee('1 kg / 2 kg');

    // Copy: written for the first placement that takes copy, counted against its limits.
    $llm->push(function (LlmRequest $r) {
        expect($r->system)->toContain('Never mention competitors.', 'English')->and($r->messages[0]['content'])->toContain('The brief: A sale on creatine, 15% off.', 'Placement: ');

        return [['submit_copy', ['versions' => [['caption' => 'Fifteen off creatine this week.', 'hashtags' => ['#creatine'], 'primary_text' => 'Stock up.', 'headline' => 'Creatine sale', 'description' => 'This week only']]]]];
    });
    $page->call('pick', 'copy')->assertSet('trialResult', null)->set('body', Starters::body(PromptType::Copy)."\n- Never mention competitors.")->call('tryIt')->assertHasNoErrors()
        ->assertSee('Written for')->assertSee('Fifteen off creatine this week.')->assertSee('#creatine')->assertSee('Version 1');
    expect($this->prompts->for($this->brand, PromptType::Copy)->current->number)->toBe(1)->and(Spend::where('kind', 'try')->count())->toBe(3);

    // Changes: the sample change on the sample scene becomes an instruction, with the edited prompt as the system text.
    $llm->push(function (LlmRequest $r) {
        expect($r->system)->toContain('Prefer edits.')->and($r->messages[0]['content'])->toContain("The prompt the original image was made from:\nA kitchen counter at dawn.", 'The new change: Make it dusk.', 'can edit the original image');

        return [['plan_change', ['mode' => 'edit', 'instruction' => 'Change the light to dusk.', 'keep' => ['the counter', 'the composition']]]];
    });
    $page->call('pick', 'change')->set('body', Starters::body(PromptType::Change)."\nPrefer edits.")->set('sampleScene', 'A kitchen counter at dawn.')->set('sampleChange', 'Make it dusk.')->call('tryIt')->assertHasNoErrors()
        ->assertSee('An edit of the original image')->assertSee('Change the light to dusk.')->assertSee('the counter; the composition')->assertSee('Change this image: Change the light to dusk.');

    // A text that wouldn't save isn't tried either.
    $page->set('body', '')->call('tryIt')->assertHasErrors('body');

    // The day's test budget, then the switch-off.
    app(Settings::class)->set(PromptTrials::SETTING, 0.0001, SettingScope::Platform, 0, $this->admin);
    $page->set('body', Starters::body(PromptType::Change))->call('tryIt')->assertHasErrors('try')->assertSee('test budget for trying prompts is used up');
    app(Settings::class)->set(PromptTrials::SETTING, 0, SettingScope::Platform, 0, $this->admin);
    $page->call('tryIt')->assertHasErrors('try')->assertSee('Trying prompts is switched off');
    expect(Spend::where('kind', 'try')->count())->toBe(4);

    // Dismissing clears the result; a lost try says so.
    $page->call('dismissTrial')->assertSet('trial', null)->assertSet('trialResult', null)
        ->set('trial', 'gone')->call('pollTrial')->assertSee('The try was lost before it finished.');
});

it('tries a review prompt on the brand\'s latest image beside the saved review, and the images prompt by making one small picture that lives a day', function () {
    app(Settings::class)->set('generation.per_ratio', 1, SettingScope::Platform, 0, $this->admin);
    $feed = Placement::where('key', 'feed-square')->whereHas('platform', fn ($q) => $q->where('key', 'instagram'))->sole();
    $llm = fakeLlm(
        [['submit_plan', ['title' => 'Whey weekend', 'summary' => 'A weekend sale.', 'image_prompt' => 'A counter.', 'image_model' => 'gemini/gemini-nano-banana-2.1']]],
        [['submit_review', ['scores' => [['criterion' => 'brief', 'score' => 90], ['criterion' => 'brand', 'score' => 80], ['criterion' => 'technical', 'score' => 90], ['criterion' => 'platform', 'score' => 90]], 'hard_fails' => [], 'reason' => 'Clean and bright.']]],
    );
    $requests = app(Requests::class);
    $request = $requests->create($this->brand, ['brief' => 'Marketing image for a sale on whey protein isolate, 20% off this weekend.', 'languages' => ['en'], 'output' => 'image', 'copy' => 'none', 'placement_ids' => [(string) $feed->id]], $this->admin);
    $requests->plan($request);
    $requests->confirm($request->fresh(), $this->admin);
    $output = $request->outputs()->sole();
    expect($output->score)->toBe(88);

    // The review try scores the saved output with the edited criteria; the reviewer sees the picture.
    $llm->push(function (LlmRequest $r) {
        expect($r->system)->toContain('Look at the shaker first.', '- shaker_colour (Shaker colour, weight 40)')->and(collect($r->messages[0]['content'])->pluck('type')->all())->toContain('image');

        return [['submit_review', ['scores' => [['criterion' => 'brief', 'score' => 90], ['criterion' => 'brand', 'score' => 80], ['criterion' => 'technical', 'score' => 90], ['criterion' => 'platform', 'score' => 90], ['criterion' => 'shaker_colour', 'score' => 20, 'note' => 'The shaker is red.']], 'hard_fails' => ['distorted_people'], 'reason' => 'The shaker is the wrong colour.']]];
    });
    $page = Livewire::test(PromptsPage::class, ['brand' => $this->brand])->call('pick', 'review_image')->assertSee('The saved review gave it 88.')
        ->set('body', str_replace('Score it honestly', 'Look at the shaker first. Score it honestly', Starters::body(PromptType::ReviewImage)))
        ->call('addCriterion')->set('criteria.4.label', 'Shaker colour')->set('criteria.4.weight', 40)->set('criteria.4.what', 'the brand blue')
        ->call('tryIt')->assertHasNoErrors()
        ->assertSee('overall with this prompt')->assertSee('88 by the saved review')->assertSee('The shaker is the wrong colour.')->assertSee('The shaker is red.')->assertSee('Hard fails: A person has distorted hands, face or body')->assertSee('Clean and bright.');
    // 30*90 + 25*80 + 25*90 + 20*90 + 40*20 = 9550 / 140 = 68
    expect($page->get('trialResult')['result']['overall'])->toBe(68)
        ->and($output->fresh()->score)->toBe(88)->and($output->review->overall)->toBe(88)
        ->and($this->prompts->for($this->brand, PromptType::ReviewImage)->current->number)->toBe(1)
        ->and(Spend::where('kind', 'try')->sole()->note)->toBe('Tried the Review: images prompt');

    // A finished-ads review has nothing to score yet.
    $page->call('pick', 'review_ad')->assertSee('Nothing to try it on yet');

    // The images try makes one square picture at the smallest size with the edited rules after the scene.
    $page->call('pick', 'image')->assertSee('Makes one square image at the smallest size')->assertSet('sampleScene', 'A counter.')
        ->set('body', Starters::body(PromptType::Image)."\nAlways a hint of blue.")->set('sampleScene', 'A tub of whey on a counter.')->call('tryIt')->assertHasNoErrors()
        ->assertSee('The prompt sent, 64×64')->assertSee("A tub of whey on a counter.\n\n".Starters::body(PromptType::Image)."\nAlways a hint of blue.")->assertSee('Nano Banana');
    $result = $page->get('trialResult')['result'];
    $sent = collect(Http::recorded())->last()[0];
    expect($result['path'])->toStartWith("prompts/tries/{$this->brand->id}/")->and(Storage::disk()->exists($result['path']))->toBeTrue()->and(Storage::disk()->exists($result['thumb']))->toBeTrue()
        ->and($sent->data()['contents'][0]['parts'][0]['text'])->toContain('Always a hint of blue.')->and($sent->data()['generationConfig']['imageConfig'])->toBe(['aspectRatio' => '1:1', 'imageSize' => '1K'])
        ->and(Spend::where('kind', 'try')->where('provider', 'gemini')->sole()->note)->toBe('Tried the Images prompt')
        ->and($this->prompts->for($this->brand, PromptType::Image)->current->number)->toBe(1);

    // The file route: admins who may edit the prompts see it; a creator doesn't.
    $url = app(PromptTrials::class)->fileUrl($this->brand, $result['thumb']);
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
    $this->get(route('prompts.try-file', [$this->brand, 'nothere.png']))->assertNotFound();
    signInAs(Role::Creator);
    $this->get($url)->assertForbidden();
    Livewire::test(PromptsPage::class, ['brand' => $this->brand])->assertDontSee('Try it before saving')->call('tryIt')->assertForbidden();

    // The nightly clean-up removes tried images after a day and says so.
    $this->actingAs($this->admin);
    $this->artisan('outputs:prune-rejects')->doesntExpectOutputToContain('tried on the Prompts page')->assertSuccessful();
    expect(Storage::disk()->exists($result['path']))->toBeTrue();
    touch(Storage::disk()->path($result['path']), now()->subDays(2)->getTimestamp());
    touch(Storage::disk()->path($result['thumb']), now()->subDays(2)->getTimestamp());
    $this->artisan('outputs:prune-rejects')->expectsOutputToContain('Removed 2 images tried on the Prompts page.')->assertSuccessful();
    expect(Storage::disk()->exists($result['path']))->toBeFalse();
});

it('runs a try as a queued job, shows it running until the result lands, and reports a job that died', function () {
    Queue::fake();
    fakeLlm();
    $page = Livewire::test(PromptsPage::class, ['brand' => $this->brand])->call('tryIt')->assertHasNoErrors()->assertSee('Trying it…')->assertSee('wire:poll.3s', false);
    Queue::assertPushed(TryPrompt::class, fn (TryPrompt $job) => $job->type === PromptType::Planner && $job->body === Starters::body(PromptType::Planner) && $job->samples['brief'] === PromptTrials::SAMPLE_BRIEF && $job->by->is($this->admin));
    $token = $page->get('trial');

    // Still running: the page keeps polling.
    $page->call('pollTrial')->assertSee('Trying it…');

    // The worker finishes it; the next poll shows the result.
    fakeLlm([['submit_plan', ['title' => 'Whey weekend', 'summary' => 'A weekend sale.', 'image_prompt' => 'A counter.', 'image_model' => 'gemini/gemini-nano-banana-2.1']]]);
    $job = collect(Queue::pushedJobs()[TryPrompt::class])->first()['job'];
    $job->handle(app(PromptTrials::class));
    $page->call('pollTrial')->assertSee('What came back')->assertSee('Whey weekend');

    // A job that died (ran out of time) is reported, not left spinning.
    $job->failed(new RuntimeException('timeout'));
    $page->call('pollTrial')->assertSee("The try didn't finish");
    expect(app(PromptTrials::class)->result($token, makeBrand()))->toBeNull();
});

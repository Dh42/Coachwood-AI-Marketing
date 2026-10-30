<?php

use App\Auth\Role;
use App\BrandKit\BrandKits;
use App\Composer\Renderer;
use App\Images\ImageModels;
use App\Livewire\Prompts\PromptsPage;
use App\Livewire\Requests\RequestPage;
use App\Llm\LlmRequest;
use App\Models\Connection;
use App\Models\Copy;
use App\Models\Placement;
use App\Models\Prompt;
use App\Models\PromptRevision;
use App\Models\Review;
use App\Models\Spend;
use App\Platforms\PlatformLibrary;
use App\Prompts\PromptAssistant;
use App\Prompts\PromptDiff;
use App\Prompts\Prompts;
use App\Prompts\PromptType;
use App\Prompts\Starters;
use App\Requests\Requests;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeRenderer;

beforeEach(function () {
    $this->admin = signInAs(Role::Admin, ['name' => 'Ada Admin']);
    $this->brand = makeBrand(['name' => 'Canadian Protein']);
    $this->prompts = app(Prompts::class);
});

it('makes each prompt from its starter when first needed, fills the placeholders, and appends only the person\'s own additions', function () {
    app(BrandKits::class)->saveText($this->brand, ['tone' => ['en' => 'Warm and direct.']], ['mood' => 'Bright mornings.'], $this->admin);

    $planner = $this->prompts->for($this->brand, PromptType::Planner);
    expect($planner->current->number)->toBe(1)->and($planner->current->method)->toBe(PromptRevision::STARTER)->and($planner->body())->toBe(Starters::body(PromptType::Planner))
        ->and(Prompt::count())->toBe(1)->and($this->prompts->for($this->brand, PromptType::Planner)->id)->toBe($planner->id);

    $resolved = $this->prompts->resolve(PromptType::Planner, $this->brand, $this->admin, ['asking' => 'Ask nothing.']);
    expect($resolved->text)->toContain("planner for Canadian Protein's", 'Voice: Warm and direct.', 'Mood: Bright mornings.', "3. Ask nothing.\n")->not->toContain('{brand', '{asking}')
        ->and($resolved->ids())->toBe(['prompt_revision_id' => $planner->current->id, 'addition_revision_id' => null]);

    // The person's own additions go after the prompt; nobody else's do.
    $this->prompts->saveAddition($this->brand, PromptType::Planner, $this->admin, "Prefer outdoor settings.\r\n");
    $mine = $this->prompts->resolve(PromptType::Planner, $this->brand, $this->admin, ['asking' => 'Ask nothing.']);
    expect($mine->text)->toEndWith("Additions from Ada Admin:\nPrefer outdoor settings.")->and($mine->additionRevision)->not->toBeNull()
        ->and($this->prompts->resolve(PromptType::Planner, $this->brand, signInAs(Role::Creator), ['asking' => 'x'])->text)->not->toContain('Additions from')
        ->and($this->prompts->resolve(PromptType::Planner, $this->brand, null, ['asking' => 'x'])->additionRevision)->toBeNull();

    // Saving the same additions again adds no revision; emptying them removes them.
    expect($this->prompts->saveAddition($this->brand, PromptType::Planner, $this->admin, 'Prefer outdoor settings.')->number)->toBe(1);
    $this->prompts->saveAddition($this->brand, PromptType::Planner, $this->admin, '  ');
    expect($this->prompts->resolve(PromptType::Planner, $this->brand, $this->admin, ['asking' => 'x'])->additionRevision)->toBeNull()
        ->and($this->prompts->additions($this->brand, PromptType::Planner))->toHaveCount(0);

    // Review prompts carry their criteria and hard fails, filled into the text.
    $review = $this->prompts->resolve(PromptType::ReviewAd, $this->brand);
    expect($review->criteria())->toHaveKeys(['brief', 'brand', 'product', 'text', 'technical'])->and($review->criteria()['product'][1])->toBe(20)
        ->and($review->hardFails())->toHaveKey('altered_label')
        ->and($review->text)->toContain('a finished ad', '- product (Product accuracy, weight 20):', '- wrong_words: A word, price or offer');
});

it('keeps every save as a revision with who and how, refuses an unchanged save, and rolls back as a new revision', function () {
    $prompt = $this->prompts->for($this->brand, PromptType::Copy);
    $v1 = $prompt->current;

    $v2 = $this->prompts->save($prompt, str_replace('no emoji unless', 'never any emoji, even when', $v1->body)."\n- Never mention competitors.", null, 'Tighter rules', PromptRevision::HAND, null, $this->admin);
    expect($v2->number)->toBe(2)->and($v2->method)->toBe(PromptRevision::HAND)->and($v2->user_id)->toBe($this->admin->id)->and($v2->note)->toBe('Tighter rules')
        ->and($prompt->fresh()->current_revision_id)->toBe($v2->id)
        ->and($this->prompts->resolve(PromptType::Copy, $this->brand, null, ['language' => 'English'])->text)->toContain('never any emoji', 'Never mention competitors.')
        ->and(Activity::where('log_name', 'prompts')->latest('id')->first()->description)->toBe("Saved Canadian Protein's copy prompt (v2, by hand)");

    $v3 = $this->prompts->save($prompt, $v2->body."\n- Always end with the brand name.", null, null, PromptRevision::AI, 'It never names the brand', $this->admin);
    expect($v3->methodLabel())->toBe('Drafted by AI from: It never names the brand');

    try {
        $this->prompts->save($prompt, $v3->body."\n", null, null, PromptRevision::HAND, null, $this->admin);
        $this->fail('Saved an unchanged prompt.');
    } catch (ValidationException $e) {
        expect($e->errors()['body'][0])->toBe('Nothing changed.');
    }

    $v4 = $this->prompts->rollBack($prompt, $v1, $this->admin);
    expect($v4->number)->toBe(4)->and($v4->method)->toBe(PromptRevision::ROLLBACK)->and($v4->body)->toBe($v1->body)->and($v4->restored_from)->toBe($v1->id)->and($v4->methodLabel())->toBe('Rolled back to v1')
        ->and($prompt->fresh()->current->id)->toBe($v4->id)
        ->and($this->prompts->history($prompt)->pluck('number')->all())->toBe([4, 3, 2, 1])
        ->and(Activity::where('log_name', 'prompts')->latest('id')->first()->description)->toBe("Rolled Canadian Protein's copy prompt back to v1 (as v4)");

    $this->prompts->rollBack($prompt, $v4, $this->admin);
})->throws(ValidationException::class, 'already the current one');

it('shows what changed between two revisions side by side', function () {
    $rows = PromptDiff::sideBySide("Keep this.\nChange me.\nDrop me.\nAnd this.", "Keep this.\nChanged.\nAnd this.\nAdded at the end.");
    expect(array_map(fn ($r) => [$r['kind'], $r['left'], $r['right']], $rows))->toBe([
        ['same', 'Keep this.', 'Keep this.'],
        ['changed', 'Change me.', 'Changed.'],
        ['removed', 'Drop me.', null],
        ['same', 'And this.', 'And this.'],
        ['added', null, 'Added at the end.'],
    ])->and(PromptDiff::changed("a\n", 'a'))->toBeFalse();
});

it('validates a review prompt: at least one criterion, weights from 1 to 100, keys made from the labels', function () {
    $prompt = $this->prompts->for($this->brand, PromptType::ReviewImage);
    $body = $prompt->body();

    $refused = function (array $settings, string $field, string $message) use ($prompt, $body) {
        try {
            $this->prompts->save($prompt, $body, $settings, null, PromptRevision::HAND, null, $this->admin);
            $this->fail('Accepted.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($field)->and($e->errors()[$field][0])->toContain($message);
        }
    };
    $refused(['criteria' => [], 'hard_fails' => []], 'criteria', 'at least one criterion');
    $refused(['criteria' => [['label' => 'Brief', 'weight' => 0, 'what' => '']], 'hard_fails' => []], 'criteria.0.weight', 'from 1 to 100');
    $refused(['criteria' => [['label' => '', 'weight' => 10, 'what' => 'something']], 'hard_fails' => []], 'criteria.0.label', 'required');

    $v2 = $this->prompts->save($prompt, $body, ['criteria' => [
        ['label' => 'Matches the brief', 'weight' => '50', 'what' => ' fits '], ['label' => 'Shaker colour', 'weight' => 50, 'what' => 'the shaker is the brand blue'], ['label' => 'Shaker colour', 'weight' => 1, 'what' => 'again'], ['label' => '', 'weight' => 3, 'what' => ''],
    ], 'hard_fails' => [['label' => 'The shaker is not blue'], ['label' => '  ']]], null, PromptRevision::HAND, null, $this->admin);
    expect(array_column($v2->criteria(), 'key'))->toBe(['matches_the_brief', 'shaker_colour', 'shaker_colour_2'])->and($v2->criteria()[0])->toBe(['key' => 'matches_the_brief', 'label' => 'Matches the brief', 'weight' => 50, 'what' => 'fits'])
        ->and($v2->hardFails())->toBe([['key' => 'the_shaker_is_not_blue', 'label' => 'The shaker is not blue']])
        ->and($v2->asText())->toContain("Criteria:\n- Matches the brief (weight 50): fits", "Hard fails:\n- The shaker is not blue");

    // The same criteria again, only the weights swapped, is still a change.
    $this->prompts->save($prompt, $body, ['criteria' => [['label' => 'Matches the brief', 'weight' => 40, 'what' => 'fits'], ['label' => 'Shaker colour', 'weight' => 60, 'what' => 'the shaker is the brand blue'], ['label' => 'Shaker colour', 'weight' => 1, 'what' => 'again']], 'hard_fails' => [['label' => 'The shaker is not blue']]], null, PromptRevision::HAND, null, $this->admin);
    expect($prompt->fresh()->current->number)->toBe(3);
});

it('drafts a change from a described problem, keeping the placeholders and the review criteria, and records the spend', function () {
    $prompt = $this->prompts->for($this->brand, PromptType::ReviewImage);
    $llm = fakeLlm(function (LlmRequest $r) {
        expect($r->system)->toContain('Review: images', '{brand_kit}: the brand kit', 'This is a review prompt')
            ->and($r->messages[0]['content'])->toContain('The problem: It keeps passing images where the shaker is the wrong colour.', '- Matches the brief (30):', "Hard fails:\n- The product's label");

        return [['propose_prompt', [
            'body' => str_replace('Score it honestly', 'Look at the shaker first. Score it honestly', Starters::body(PromptType::ReviewImage)),
            'criteria' => [...array_map(fn ($c) => ['label' => $c['label'], 'weight' => $c['weight'], 'what' => $c['what']], Starters::settings(PromptType::ReviewImage)['criteria']), ['label' => 'Shaker colour', 'weight' => 20, 'what' => 'the shaker is the brand blue, never another colour']],
            'hard_fails' => [...array_map(fn ($f) => ['label' => $f['label']], Starters::settings(PromptType::ReviewImage)['hard_fails']), ['label' => 'The shaker is not blue']],
            'explanation' => 'Added a shaker colour criterion and a hard fail for the wrong colour.',
        ]]];
    });

    $draft = app(PromptAssistant::class)->draft($prompt, 'It keeps passing images where the shaker is the wrong colour.', $this->admin);
    expect($draft['body'])->toContain('Look at the shaker first.', '{brand_kit}', '{criteria}')
        ->and(array_column($draft['settings']['criteria'], 'key'))->toBe(['brief', 'brand', 'technical', 'platform', 'shaker_colour'])
        ->and(array_column($draft['settings']['hard_fails'], 'key'))->toContain('the_shaker_is_not_blue')
        ->and($draft['explanation'])->toStartWith('Added a shaker colour criterion')
        ->and($prompt->fresh()->current->number)->toBe(1)
        ->and(Spend::where('kind', 'prompt')->sole()->only(['brand_id', 'user_id']))->toBe(['brand_id' => $this->brand->id, 'user_id' => $this->admin->id]);

    // A model that answers in words twice gives nothing to show.
    $llm->push('Hmm.', 'Still thinking.');
    app(PromptAssistant::class)->draft($prompt, 'It keeps passing images where the shaker is the wrong colour.', $this->admin);
})->throws(RuntimeException::class, 'sent no changed prompt');

it('records the revisions used on the request, its generations and its reviews', function () {
    Storage::fake();
    app()->instance(Renderer::class, new FakeRenderer);
    app(PlatformLibrary::class)->addStarters($this->admin);
    app(ImageModels::class)->addStarters($this->admin);
    Connection::factory()->create(['plugin' => 'gemini', 'name' => 'Gemini', 'brand_id' => null, 'config' => [], 'secrets' => ['api_key' => 'k'], 'status' => Connection::STATUS_CONNECTED]);
    app(Settings::class)->set('generation.per_ratio', 1, SettingScope::Platform, 0, $this->admin);
    $im = imagecreatetruecolor(64, 64);
    ob_start();
    imagepng($im);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode((string) ob_get_clean())]]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 1120]])]);
    $feed = Placement::where('key', 'feed-square')->whereHas('platform', fn ($q) => $q->where('key', 'instagram'))->sole();

    // The brand's prompts have moved on from their starters, and the person has additions to the image rules.
    $planner = $this->prompts->for($this->brand, PromptType::Planner);
    $plannerV2 = $this->prompts->save($planner, $planner->body()."\nAlways plan a morning scene.", null, null, PromptRevision::HAND, null, $this->admin);
    $imageAddition = $this->prompts->saveAddition($this->brand, PromptType::Image, $this->admin, 'Soft film grain.');
    $reviewV1 = $this->prompts->for($this->brand, PromptType::ReviewImage)->current;

    fakeLlm(
        function (LlmRequest $r) {
            expect($r->system)->toContain('Always plan a morning scene.');

            return [['submit_plan', ['title' => 'Whey weekend', 'summary' => 'A weekend sale.', 'offer' => ['headline' => '20% off'], 'image_prompt' => 'A counter.', 'image_model' => 'gemini/gemini-nano-banana-2.1']]];
        },
        fn (LlmRequest $r) => [['submit_copy', ['versions' => [['caption' => 'Twenty off this weekend.', 'hashtags' => ['#whey']]]]]],
        fn (LlmRequest $r) => [['submit_review', ['scores' => [['criterion' => 'brief', 'score' => 90], ['criterion' => 'brand', 'score' => 90], ['criterion' => 'technical', 'score' => 90], ['criterion' => 'platform', 'score' => 90]], 'hard_fails' => [], 'reason' => 'Fine.']]],
    );
    $requests = app(Requests::class);
    $request = $requests->create($this->brand, ['brief' => 'Marketing image for a sale on whey protein isolate, 20% off this weekend.', 'languages' => ['en'], 'output' => 'image', 'copy' => 'captions', 'placement_ids' => [(string) $feed->id]], $this->admin);
    $requests->plan($request);
    $requests->confirm($request->fresh(), $this->admin);
    $request->refresh();

    expect($request->prompt_revision_id)->toBe($plannerV2->id)->and($request->addition_revision_id)->toBeNull()
        ->and($request->generations)->toHaveCount(1)
        ->and($request->generations[0]->prompt)->toBe("A counter.\n\n".Starters::body(PromptType::Image)."\n\nAdditions from Ada Admin:\nSoft film grain.")
        ->and($request->generations[0]->prompt_revision_id)->toBe($this->prompts->for($this->brand, PromptType::Image)->current->id)
        ->and($request->generations[0]->addition_revision_id)->toBe($imageAddition->id)
        ->and(Copy::sole()->prompt_revision_id)->toBe($this->prompts->for($this->brand, PromptType::Copy)->current->id)
        ->and(Review::sole()->prompt_revision_id)->toBe($reviewV1->id)->and(Review::sole()->overall)->toBe(90)
        ->and($this->prompts->usedBy($request))->toBe(['Planner v2', "Images v1 + Ada Admin's additions v1", 'Copy v1', 'Review: images v1']);
    Livewire::test(RequestPage::class, ['brand' => $this->brand, 'request' => $request])->assertSee("Planner v2; Images v1 + Ada Admin's additions v1; Copy v1; Review: images v1");
});

it('lets admins edit, draft with AI, accept and roll back on the page, creators keep additions only, and viewers read', function () {
    $page = Livewire::test(PromptsPage::class, ['brand' => $this->brand])->assertSee('Planner')->assertSee('v1 · Starting text')->assertSee('Save as a new version')->assertSee('Your additions');

    // Edit by hand.
    $page->call('pick', 'copy')->assertSet('type', 'copy')->set('body', '')->call('save')->assertHasErrors('body')
        ->set('body', Starters::body(PromptType::Copy)."\n- Never mention competitors.")->set('note', 'Competitors')->call('save')->assertHasNoErrors()->assertSee('v2 · Edited by hand by Ada Admin')->assertSet('note', '');

    // Describe the problem: the draft is shown as a diff, then saved as an AI revision with the request.
    $llm = fakeLlm([['propose_prompt', ['body' => Starters::body(PromptType::Copy)."\n- Never mention competitors.\n- Always end with the brand name.", 'explanation' => 'Added a closing rule.']]]);
    $page->set('problem', 'short')->call('askAi')->assertHasErrors('problem')
        ->set('problem', 'The copy never names the brand at the end.')->call('askAi')->assertHasNoErrors()->assertSee('Added a closing rule.')->assertSee('Always end with the brand name.')->assertSee('Proposed')
        ->call('discardDraft')->assertDontSee('Added a closing rule.')->assertSet('draft', null);
    $llm->push([['propose_prompt', ['body' => Starters::body(PromptType::Copy)."\n- Never mention competitors.\n- Always end with the brand name.", 'explanation' => 'Added a closing rule.']]]);
    $page->call('askAi')->call('acceptDraft')->assertHasNoErrors()->assertSee('v3 · Drafted by AI from: The copy never names the brand at the end.');
    expect($this->prompts->for($this->brand, PromptType::Copy)->current->only(['number', 'method', 'request']))->toBe(['number' => 3, 'method' => PromptRevision::AI, 'request' => 'The copy never names the brand at the end.']);

    // Adjusting a draft before saving still records the problem it came from.
    $llm->push([['propose_prompt', ['body' => Starters::body(PromptType::Copy)."\n- Never mention competitors.\n- Always end with the brand name.\n- No exclamation marks.", 'explanation' => 'Calmer.']]]);
    $page->set('problem', 'Too many exclamation marks.')->call('askAi')->call('adjustDraft')->assertSet('draft', null)->assertSee('Save the adjusted draft')
        ->set('body', Starters::body(PromptType::Copy)."\n- Never mention competitors.\n- Always end with the brand name.\n- At most one exclamation mark.")->call('save')->assertHasNoErrors();
    expect($this->prompts->for($this->brand, PromptType::Copy)->current->only(['number', 'method', 'request']))->toBe(['number' => 4, 'method' => PromptRevision::AI, 'request' => 'Too many exclamation marks.']);

    // History with a diff, and a rollback that needs a second click.
    $v2 = PromptRevision::where('number', 2)->whereHas('prompt', fn ($q) => $q->where('type', 'copy'))->sole();
    $page->call('toggleHistory')->assertSee('Competitors')->assertDontSee('Rolled back')
        ->call('showDiff', $v2->id)->assertSee('What changed')->assertSee('Never mention competitors.')
        ->call('askRestore', $v2->id)->assertSee('Yes, use v2')->call('keep')->assertDontSee('Yes, use v2')
        ->call('askRestore', $v2->id)->call('restore', $v2->id)->assertHasNoErrors()->assertSee('v5 · Rolled back to v2');

    // Review prompts edit their criteria too.
    $page->call('pick', 'review_ad')->assertSee('Criteria')->assertSet('criteria.2.label', 'Product accuracy')->assertSet('criteria.2.key', 'product')->call('addCriterion')->set('criteria.5.label', 'Shaker colour')->set('criteria.5.weight', 10)->set('criteria.5.what', 'the brand blue')
        ->call('addHardFail')->set('hardFails.4.label', 'The shaker is not blue')->call('save')->assertHasNoErrors();
    expect($this->prompts->resolve(PromptType::ReviewAd, $this->brand)->criteria())->toHaveKey('shaker_colour')->and($this->prompts->resolve(PromptType::ReviewAd, $this->brand)->hardFails())->toHaveKey('the_shaker_is_not_blue');

    // A missing placeholder is pointed out, not refused.
    $page->call('pick', 'image')->assertDontSee('The app fills in')->call('pick', 'planner')->set('body', 'Plan it.')->assertSee('no longer has {brand}, {brand_kit}, {asking}');

    // Creators read the brand's prompt and keep their own additions; the brand's prompt is not theirs to save.
    $creator = signInAs(Role::Creator, ['name' => 'Cal Creator']);
    $creatorPage = Livewire::test(PromptsPage::class, ['brand' => $this->brand])->assertDontSee('Save as a new version')->assertDontSee('Describe the problem')->assertSee('Your additions')
        ->set('addition', 'Prefer outdoor settings.')->call('saveAddition')->assertHasNoErrors();
    expect($this->prompts->resolve(PromptType::Planner, $this->brand, $creator, ['asking' => 'x'])->text)->toContain("Additions from Cal Creator:\nPrefer outdoor settings.");
    $creatorPage->call('save')->assertForbidden();
    Livewire::test(PromptsPage::class, ['brand' => $this->brand])->call('restore', $v2->id)->assertForbidden();

    // Admins read everyone's additions; viewers only read.
    $this->actingAs($this->admin);
    Livewire::test(PromptsPage::class, ['brand' => $this->brand])->assertSee('Additions by others')->assertSee('Cal Creator')->assertSee('Prefer outdoor settings.');
    signInAs(Role::Viewer);
    Livewire::test(PromptsPage::class, ['brand' => $this->brand])->assertDontSee('Your additions')->assertDontSee('Save as a new version')->assertSee('You are the planner')
        ->call('saveAddition')->assertForbidden();
    $this->get(route('prompts', $this->brand))->assertOk();
});

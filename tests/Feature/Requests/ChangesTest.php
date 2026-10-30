<?php

use App\Auth\Role;
use App\Images\ImageModels;
use App\Jobs\GenerateImage;
use App\Livewire\Requests\NewRequest;
use App\Livewire\Requests\RequestPage;
use App\Models\Connection;
use App\Models\CreativeRequest;
use App\Models\Generation;
use App\Models\ImageModel;
use App\Models\Output;
use App\Models\Placement;
use App\Models\Spend;
use App\Platforms\PlatformLibrary;
use App\Prompts\Prompts;
use App\Prompts\PromptType;
use App\Prompts\Starters;
use App\Requests\Changes;
use App\Requests\Requests;
use App\Review\Reviewer;
use App\Review\Shortlist;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\FakeReviewer;

beforeEach(function () {
    Storage::fake();
    app()->instance(Reviewer::class, new FakeReviewer(80));
    Sleep::fake();
    $this->admin = signInAs(Role::Admin, ['name' => 'Ada Admin']);
    $this->brand = makeBrand(['name' => 'Canadian Protein']);
    app(PlatformLibrary::class)->addStarters($this->admin);
    app(ImageModels::class)->addStarters($this->admin);
    Connection::factory()->create(['plugin' => 'gemini', 'name' => 'Gemini', 'brand_id' => null, 'config' => [], 'secrets' => ['api_key' => 'k'], 'status' => Connection::STATUS_CONNECTED]);
    app(Settings::class)->set('generation.per_ratio', 1, SettingScope::Platform, 0, $this->admin);
    $this->requests = app(Requests::class);
    $this->changes = app(Changes::class);

    $ids = Placement::whereIn('key', ['feed-square'])->whereHas('platform', fn ($q) => $q->where('key', 'instagram'))->pluck('id')->map(fn ($i) => (string) $i)->all();
    $this->llm = fakeLlm([['submit_plan', ['title' => 'Whey weekend', 'summary' => 'A sale on whey.', 'image_prompt' => 'A kitchen counter in morning light.', 'image_model' => 'gemini/gemini-nano-banana-2.1']]]);
    $this->request = $this->requests->create($this->brand, ['brief' => 'Marketing image for a sale on whey protein isolate, 20% off this weekend.', 'languages' => ['en'], 'output' => 'image', 'copy' => 'none', 'placement_ids' => $ids], $this->admin);
    $this->requests->plan($this->request);

    $im = imagecreatetruecolor(64, 64);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 180, 160));
    ob_start();
    imagepng($im);
    $this->png = (string) ob_get_clean();
    $this->image = fn () => ['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($this->png)]]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 1120], 'modelVersion' => 'nb-2.1'];
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(($this->image)())]);

    $this->requests->confirm($this->request->fresh(), $this->admin);
    $this->request->refresh();
    $this->original = $this->request->outputs()->sole();
    Http::assertSentCount(1);
});

it('makes a change from the original image: the planner folds the instruction, the edit is sent with the original, the result is reviewed, judged and linked', function () {
    $this->llm->push([['plan_change', ['mode' => 'edit', 'instruction' => 'Make the background darker.', 'keep' => ['the counter', 'the morning light']]]]);
    $costBefore = (float) $this->request->actual_cost;

    $generation = $this->changes->change($this->original, '  darker   background ', $this->admin);
    $generation->refresh();
    $version = $generation->outputs()->sole();

    expect($generation->isChange())->toBeTrue()->and($generation->source_output_id)->toBe($this->original->id)->and($generation->user_id)->toBe($this->admin->id)
        ->and($generation->instruction)->toBe('darker background')->and($generation->status)->toBe(Generation::DONE)->and($generation->count)->toBe(1)
        ->and($generation->change['mode'])->toBe('edit')->and($generation->change['source_id'])->toBe($this->original->id)->and($generation->change['folded'])->toBe([])
        ->and($generation->change['prompt_revision_id'])->toBe(app(Prompts::class)->for($this->brand, PromptType::Change)->current->id)
        ->and($generation->prompt)->toBe("Change this image: Make the background darker.\n\nKeep as it is: the counter; the morning light.\n\n".Starters::body(PromptType::Image))
        ->and($version->source_id)->toBe($this->original->id)->and($version->kind)->toBe(Output::IMAGE)->and($version->score)->toBe(80)->and($version->status)->toBe(Shortlist::SHORTLISTED)
        ->and($version->root()->id)->toBe($this->original->id)->and($version->depth())->toBe(1)
        ->and($this->original->fresh()->status)->toBe(Shortlist::SHORTLISTED)
        ->and(Spend::where('request_id', $this->request->id)->where('kind', 'change')->count())->toBe(1)
        ->and((float) $this->request->fresh()->actual_cost)->toBeGreaterThan($costBefore)
        ->and($this->request->fresh()->status)->toBe(CreativeRequest::DONE);

    // The planner was told the original prompt, that no change was asked before, and the new one.
    $planning = $this->llm->requests[1];
    expect($planning->messages[0]['content'])->toContain('A kitchen counter in morning light.', 'Changes already asked on it: none.', 'The new change: darker background', 'can edit the original image');

    // The original image went with the instruction.
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => str_starts_with($r['contents'][0]['parts'][0]['text'], 'Change this image: Make the background darker.')
        && $r['contents'][0]['parts'][1]['text'] === 'Reference image 1: the image to change; keep everything not mentioned exactly as it is'
        && base64_decode($r['contents'][0]['parts'][2]['inline_data']['data']) === $this->png);

    expect(app(Prompts::class)->usedBy($this->request->fresh()))->toContain('Changes v1');

    // A second change on the version is still made from the original, with both changes folded.
    $this->llm->push([['plan_change', ['mode' => 'edit', 'instruction' => 'Make the background darker and remove the shaker.', 'keep' => []]]]);
    $second = $this->changes->change($version, 'no shaker', $this->admin)->fresh();
    $v2 = $second->outputs()->sole();

    expect($second->change['source_id'])->toBe($this->original->id)->and($second->change['folded'])->toBe(['darker background'])
        ->and($v2->source_id)->toBe($version->id)->and($v2->depth())->toBe(2)->and($v2->root()->id)->toBe($this->original->id)
        ->and($this->llm->requests[2]->messages[0]['content'])->toContain("Changes already asked on it:\n- darker background", 'The new change: no shaker')
        ->and($this->changes->changesLeft($this->original))->toBe(1)->and($this->changes->changesLeft($v2))->toBe(1)
        ->and(array_map(fn ($o) => $o->id, $this->original->fresh()->tree()))->toBe([$this->original->id, $version->id, $v2->id]);
    Http::assertSent(fn ($r) => base64_decode($r['contents'][0]['parts'][2]['inline_data']['data'] ?? '') === $this->png);

    // Whoever asked is told how it went.
    $notices = $this->admin->notifications()->where('data->event', 'changes')->get();
    expect($notices)->toHaveCount(2)->and($notices->pluck('data.title')->unique()->all())->toBe(['Your change on "Whey weekend" is ready'])
        ->and($notices->pluck('data.body')->all())->toContain('"darker background": scored 80, and is on the shortlist.', '"no shaker": scored 80, and is on the shortlist.');
});

it('writes the prompt again when the change is global or the model can\'t edit, and rejects a version that scores under the minimum', function () {
    ImageModel::findByKey('gemini/gemini-nano-banana-2.1')->update(['edits' => false]);
    app()->instance(Reviewer::class, new FakeReviewer(40));
    $this->llm->push([['plan_change', ['mode' => 'edit', 'instruction' => 'At dusk.', 'prompt' => 'A kitchen counter at dusk, warm lamps.', 'keep' => ['the counter']]]]);

    $generation = $this->changes->change($this->original, 'make it evening', $this->admin)->fresh();
    $version = $generation->outputs()->sole();

    expect($generation->change['mode'])->toBe('regenerate')
        ->and($generation->prompt)->toBe("A kitchen counter at dusk, warm lamps.\n\nKeep as it is: the counter.\n\n".Starters::body(PromptType::Image))
        ->and($this->llm->requests[1]->messages[0]['content'])->toContain('can\'t edit an image')
        ->and($version->status)->toBe(Shortlist::REJECTED)->and($version->reason)->toContain('Scored 40, under the minimum of 70')
        ->and($this->original->fresh()->status)->toBe(Shortlist::SHORTLISTED);
    Http::assertSent(fn ($r) => count($r['contents'][0]['parts']) === 1 && str_starts_with($r['contents'][0]['parts'][0]['text'], 'A kitchen counter at dusk'));
});

it('fails the change, not the request, when the planner gives nothing or the provider refuses', function () {
    $this->llm->push('I would rather not.', 'Still no.');
    $generation = $this->changes->change($this->original, 'something odd', $this->admin)->fresh();
    expect($generation->status)->toBe(Generation::FAILED)->and($generation->error)->toContain('couldn\'t turn this change into an instruction')->and($generation->outputs()->count())->toBe(0)
        ->and($this->request->fresh()->status)->toBe(CreativeRequest::DONE)->and($this->changes->cannotChange($this->original))->toBeNull();

    $page = $this->get(route('requests.show', [$this->brand, $this->request]))->assertOk();
    $page->assertSee('something odd')->assertSee('turn this change into an instruction')->assertSee('Make a change');
});

it('refuses a change over the limit, on an image still changing, while the run is going, over budget, or by someone who may not', function () {
    app(Settings::class)->set('iterate.max_changes', 1, SettingScope::Platform, 0, $this->admin);
    $this->llm->push([['plan_change', ['mode' => 'edit', 'instruction' => 'Darker.', 'keep' => []]]]);
    $version = $this->changes->change($this->original, 'darker', $this->admin)->fresh()->outputs()->sole();

    expect($this->changes->cannotChange($this->original))->toBe('This image has had 1 change. Start a fresh request with the changes folded into the brief.')
        ->and($this->changes->cannotChange($version))->toContain('has had 1 change')
        ->and(fn () => $this->changes->change($version, 'lighter', $this->admin))->toThrow(ValidationException::class, 'has had 1 change')
        ->and(fn () => $this->changes->change($this->original, 'x', $this->admin))->toThrow(ValidationException::class, 'Say what to change.');

    // The page says so and offers a fresh request with the changes folded into the brief.
    $page = $this->get(route('requests.show', [$this->brand, $this->request]))->assertOk();
    $page->assertSee('has had 1 change')->assertSee('Start a fresh request')->assertDontSee('Make a change');
    Livewire::withQueryParams(['from' => $this->request->id, 'output' => $version->id])->test(NewRequest::class, ['brand' => $this->brand])
        ->assertSet('form.brief', "Marketing image for a sale on whey protein isolate, 20% off this weekend.\n\nChanges wanted from the last run: darker.")
        ->assertSet('form.output', 'image');

    // A change still being made blocks another on the same image.
    app(Settings::class)->set('iterate.max_changes', 5, SettingScope::Platform, 0, $this->admin);
    Bus::fake([GenerateImage::class]);
    $this->changes->change($this->original, 'brighter', $this->admin);
    expect($this->changes->cannotChange($this->original))->toBe('A change on this image is still being made.')
        ->and($this->request->fresh()->isBusy())->toBeTrue();
    Generation::where('instruction', 'brighter')->delete();

    // Over budget.
    app(Settings::class)->set('budgets.company_monthly', 0.0001, SettingScope::Platform, 0, $this->admin);
    expect(fn () => $this->changes->change($this->original, 'brighter', $this->admin))->toThrow(ValidationException::class, 'monthly budget');
    app(Settings::class)->set('budgets.company_monthly', 500, SettingScope::Platform, 0, $this->admin);

    // Not while running.
    $this->request->update(['status' => CreativeRequest::RUNNING]);
    expect($this->changes->cannotChange($this->original->fresh()))->toBe('Wait for the run to finish.');
    $this->request->update(['status' => CreativeRequest::DONE]);

    // A viewer can't; a creator can't on someone else's request.
    signInAs(Role::Viewer);
    Livewire::test(RequestPage::class, ['brand' => $this->brand, 'request' => $this->request])->call('askChange', $this->original->id)->assertForbidden();
    signInAs(Role::Creator);
    Livewire::test(RequestPage::class, ['brand' => $this->brand, 'request' => $this->request])->call('runAgain', $this->request->generations()->first()->id)->assertForbidden();
});

it('runs a shape again with the plan unchanged: as many images as the run made, judged, with the prompts as they are now', function () {
    app(Settings::class)->set('generation.per_ratio', 3, SettingScope::Platform, 0, $this->admin);
    $this->request->targets()->update(['count' => 2]);
    app(Prompts::class)->saveAddition($this->brand, PromptType::Image, $this->admin, 'Soft film grain.');
    $first = $this->request->generations()->first();

    $again = $this->changes->again($first, $this->admin)->fresh();

    expect($again->isChange())->toBeFalse()->and($again->user_id)->toBe($this->admin->id)->and($again->ratio)->toBe('1:1')->and($again->count)->toBe(2)
        ->and($again->status)->toBe(Generation::DONE)->and($again->done)->toBe(2)
        ->and($again->prompt)->toBe("A kitchen counter in morning light.\n\n".Starters::body(PromptType::Image)."\n\nAdditions from Ada Admin:\nSoft film grain.")
        ->and($again->outputs()->count())->toBe(2)->and($again->outputs()->where('status', Shortlist::SHORTLISTED)->count())->toBe(2)
        ->and($this->original->fresh()->status)->toBe(Shortlist::SHORTLISTED)
        ->and($this->request->fresh()->outputs()->count())->toBe(3)
        ->and($this->changes->cannotRunAgain($first))->toBeNull();
    Http::assertSentCount(3);

    $page = $this->get(route('requests.show', [$this->brand, $this->request]))->assertOk();
    $page->assertSee('1:1 again')->assertSee('by Ada Admin')->assertSee('Run again')->assertSee('3 images');
    expect($this->admin->notifications()->where('data->event', 'changes')->sole()->data)->toMatchArray(['title' => 'The 1:1 shape of "Whey weekend" has run again', 'body' => '2 of 2 new images passed review and are on the shortlist.']);
});

it('shows a change on the page: the form, the version under its original with both scores, and a drop flagged', function () {
    $this->llm->push([['plan_change', ['mode' => 'edit', 'instruction' => 'Darker.', 'keep' => []]]]);

    $page = Livewire::test(RequestPage::class, ['brand' => $this->brand, 'request' => $this->request]);
    $page->assertSee('Make a change')->assertDontSee('Make it')
        ->call('askChange', $this->original->id)->assertSet('changing', $this->original->id)->assertSee('Make it')
        ->set('instruction', 'x')->call('makeChange')->assertHasErrors('instruction')
        ->call('keepImage')->assertSet('changing', null)->assertDontSee('Make it');

    app()->instance(Reviewer::class, new FakeReviewer(72));
    $page->call('askChange', $this->original->id)->set('instruction', 'darker background')->call('makeChange')->assertHasNoErrors()->assertSet('changing', null)->assertDispatched('saved');

    $version = Output::where('source_id', $this->original->id)->sole();
    expect($version->score)->toBe(72);
    $html = $this->get(route('requests.show', [$this->brand, $this->request]))->assertOk();
    $html->assertSee('“darker background”', false)->assertSee('Ada Admin')->assertSee('down from 80')->assertSee('Shortlisted');
});

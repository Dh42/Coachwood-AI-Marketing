<?php

use App\Auth\Role;
use App\Budgets\Budgets;
use App\Images\ImageModels;
use App\Jobs\GenerateImage;
use App\Jobs\PlanRequest;
use App\Livewire\Requests\NewRequest;
use App\Livewire\Requests\RequestPage;
use App\Livewire\Requests\RequestsIndex;
use App\Llm\LlmRequest;
use App\Models\CreativeRequest;
use App\Models\Placement;
use App\Models\PlacementPreset;
use App\Models\Product;
use App\Models\Spend;
use App\Platforms\PlatformLibrary;
use App\Requests\Planner;
use App\Requests\RequestReferences;
use App\Requests\Requests;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeLlm;

beforeEach(function () {
    Storage::fake();
    $this->admin = signInAs(Role::Admin);
    $this->brand = makeBrand(['name' => 'Canadian Protein']);
    app(PlatformLibrary::class)->addStarters($this->admin);
    app(ImageModels::class)->addStarters($this->admin);
    $this->requests = app(Requests::class);

    $this->whey = Product::factory()->create(['brand_id' => $this->brand->id, 'title' => 'Whey Protein Isolate', 'tags' => ['whey']]);
    $this->whey->images()->create(['external_id' => 'img-1', 'url' => 'https://cdn.example/whey.png', 'position' => 0]);
    $this->vanilla = $this->whey->variants()->create(['external_id' => 'v1', 'title' => '1 kg / Vanilla', 'price' => '49.99', 'available' => true, 'options' => ['Size' => '1 kg', 'Flavour' => 'Vanilla'], 'position' => 1]);
    $this->chocolate = $this->whey->variants()->create(['external_id' => 'v2', 'title' => '2 kg / Chocolate', 'price' => '89.99', 'available' => true, 'options' => ['Size' => '2 kg', 'Flavour' => 'Chocolate'], 'position' => 2]);
    $plus = Product::factory()->create(['brand_id' => $this->brand->id, 'title' => 'Whey Isolate Plus']);
    $plus->variants()->create(['external_id' => 'v3', 'title' => 'Default Title', 'price' => '59.99', 'available' => true, 'options' => [], 'position' => 1]);

    $this->feed = Placement::where('key', 'feed-portrait')->whereHas('platform', fn ($q) => $q->where('key', 'instagram'))->sole();
    $this->square = Placement::where('key', 'feed-square')->whereHas('platform', fn ($q) => $q->where('key', 'instagram'))->sole();
    $this->story = Placement::where('key', 'stories')->whereHas('platform', fn ($q) => $q->where('key', 'instagram'))->sole();
});

function requestInput(array $more = []): array
{
    return ['brief' => 'Marketing image for a sale on whey protein isolate, 20% off this weekend.', 'languages' => ['en'], 'output' => 'ad', 'ad_style' => 'composed', 'copy' => 'all',
        'placement_ids' => [(string) test()->feed->id, (string) test()->square->id, (string) test()->story->id], 'per_ratio' => '', 'image_model' => '', 'product_variant_id' => '', 'public' => true, ...$more];
}

function planReply(array $more = []): array
{
    return [['submit_plan', ['title' => 'Whey isolate 20% off weekend', 'summary' => 'A weekend sale on whey isolate.', 'offer' => ['headline' => '20% off whey isolate', 'details' => 'This weekend'],
        'audience' => 'Gym-goers', 'cta' => 'Shop now', 'product_variant_id' => test()->vanilla->id, 'image_prompt' => 'A clean kitchen counter in morning light with space on the right for the tub.',
        'ratio_notes' => ['9:16' => 'Leave headroom.'], 'image_model' => 'gemini/gemini-nano-banana-2.1', 'model_reason' => 'Cheap and good.', ...$more]]];
}

it('plans a request: looks the product up, asks which size, then makes one target per placement with an estimate', function () {
    $llm = fakeLlm(
        [['search_products', ['query' => 'whey protein isolate']]],
        fn (LlmRequest $r) => FakeLlm::tools([['ask_questions', ['questions' => [
            ['id' => 'variant', 'question' => 'Which size and flavour?', 'type' => 'variant', 'options' => [['value' => (string) test()->vanilla->id, 'label' => 'x'], ['value' => (string) test()->chocolate->id, 'label' => 'y']]],
            ['id' => 'dates', 'question' => 'Which dates?', 'type' => 'text'],
        ]]]]),
    );

    $request = $this->requests->create($this->brand, requestInput(), $this->admin);
    $this->requests->plan($request);
    $request->refresh();

    // The first message carried the brief, the shapes, the models and the brand; the tool result carried both products.
    $first = $llm->requests[0];
    expect($first->brandId)->toBe($this->brand->id)->and($first->system)->toContain('Canadian Protein')->and($first->messages[0]['content'])->toContain('4:5', '1:1', '9:16', 'gemini/gemini-nano-banana-2.1', 'Images per shape: 8')
        ->and($llm->requests[1]->messages[2]['content'][0]['content'])->toContain('Whey Protein Isolate', 'Whey Isolate Plus', '1 kg · Vanilla');

    expect($request->status)->toBe(CreativeRequest::ASKING)->and($request->rounds)->toBe(1)
        ->and($request->questions[0]['type'])->toBe('variant')->and($request->questions[0]['options'][0]['label'])->toBe('Whey Protein Isolate: 1 kg · Vanilla ($49.99)')->and($request->questions[0]['options'][0]['image'])->toBe('https://cdn.example/whey.png')
        ->and($request->questions[1]['allow_other'])->toBeTrue()
        ->and(Spend::where('request_id', $request->id)->where('kind', 'plan')->sum('amount'))->toBeGreaterThan(0);

    // Answers go back to the planner with the chosen variant already set.
    $llm->push(planReply());
    try {
        $this->requests->answer($request, ['variant' => '', 'dates' => 'Saturday and Sunday'], $this->admin);
        $this->fail('Accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('answers.variant');
    }
    $this->requests->answer($request, ['variant' => (string) $this->vanilla->id, 'dates' => 'Saturday and Sunday'], $this->admin);
    $request->refresh();

    expect($llm->requests[2]->messages[0]['content'])->toContain('Q: Which size and flavour?', 'A: Whey Protein Isolate: 1 kg · Vanilla', 'A: Saturday and Sunday', 'Product already chosen')
        ->and($request->status)->toBe(CreativeRequest::PLANNED)->and($request->title)->toBe('Whey isolate 20% off weekend')->and($request->product_variant_id)->toBe($this->vanilla->id)
        ->and($request->plan('offer.headline'))->toBe('20% off whey isolate')->and($request->plan('image_model'))->toBe('gemini/gemini-nano-banana-2.1')->and($request->plan('ratio_notes'))->toBe(['9:16' => 'Leave headroom.'])
        ->and($request->targets)->toHaveCount(3)
        // Three shapes at 8 images each at $0.0336: $0.8064, plus the $1 allowance for writing and review.
        ->and(round($request->image_cost, 4))->toBe(0.8064)->and(round($request->estimated_cost, 4))->toBe(1.8064)
        ->and($request->targets->sum('estimated_cost'))->toEqualWithDelta(0.8064, 0.0001);

    $rows = $this->requests->planRows($request);
    expect(array_column($rows, 'ratio'))->toBe(['1:1', '4:5', '9:16'])->and($rows[0]['placements'])->toBe(['Instagram · Feed (1:1) (1080 × 1080)'])->and($rows[0]['model']->label)->toBe('Nano Banana 2.1');
});

it('uses the person\'s choices over the planner\'s: the model, the count and the product', function () {
    fakeLlm(planReply(['image_model' => 'openai/gpt-image-2', 'product_variant_id' => $this->chocolate->id]));
    app(Settings::class)->set('images.default_size', '2K', SettingScope::Brand, $this->brand->id, $this->admin);

    $request = $this->requests->create($this->brand, requestInput(['image_model' => 'gemini/gemini-3-pro-image', 'per_ratio' => '2', 'product_variant_id' => (string) $this->vanilla->id, 'placement_ids' => [(string) $this->feed->id]]), $this->admin);
    $this->requests->plan($request);
    $request->refresh();

    // One shape, 2 images of Nano Banana Pro at 2K ($0.134 each).
    expect($request->status)->toBe(CreativeRequest::PLANNED)->and($request->targets->sole()->image_model)->toBe('gemini/gemini-3-pro-image')->and($request->targets->sole()->count)->toBe(2)
        ->and(round($request->image_cost, 3))->toBe(0.268)->and($request->product_variant_id)->toBe($this->vanilla->id)->and($request->plan('size'))->toBe('2K');
});

it('stops asking after the allowed rounds, falls back when the planner names an unknown model or product, and records a failure', function () {
    app(Settings::class)->set('requests.max_rounds', 1, SettingScope::Platform, 0, $this->admin);
    $llm = fakeLlm(planReply(['image_model' => 'nope/none', 'product_variant_id' => 999999]));

    $request = $this->requests->create($this->brand, requestInput(['placement_ids' => [(string) $this->feed->id]]), $this->admin);
    $request->update(['rounds' => 1]);
    $this->requests->plan($request);
    $request->refresh();

    expect($llm->requests[0]->system)->toContain('No more questions')->and(collect($llm->requests[0]->tools)->pluck('name')->all())->not->toContain('ask_questions')
        ->and($request->status)->toBe(CreativeRequest::PLANNED)->and($request->product_variant_id)->toBeNull()->and($request->plan('image_model'))->toBe('gemini/gemini-nano-banana-2.1');

    // A planner that talks instead of planning is nudged, then given up on.
    $llm->push('I think we should...', 'Still thinking.', 'Hmm.', 'More.', 'And more.', 'Again.', 'Yes.', 'No.', 'Maybe.');
    $this->requests->change($request, requestInput(['brief' => 'Something else entirely, a new brief here.']), $this->admin);
    $request->refresh();
    expect($request->status)->toBe(CreativeRequest::FAILED)->and($request->error)->toContain('didn\'t reach a plan')->and($request->plan)->toBeNull()->and($request->targets)->toHaveCount(0);

    // Try again works from a failure.
    $llm->push(planReply());
    $this->requests->plan($request);
    expect($request->fresh()->status)->toBe(CreativeRequest::PLANNED);
});

it('confirms a plan only within the person\'s and the company\'s budget, counting confirmed runs as spent', function () {
    fakeLlm(planReply(), planReply(), planReply());
    Bus::fake([GenerateImage::class]);
    $budgets = app(Budgets::class);
    app(Settings::class)->set('budgets.user_monthly', 3, SettingScope::Platform, 0, $this->admin);
    app(Settings::class)->set('budgets.company_monthly', 5, SettingScope::Platform, 0, $this->admin);

    $first = $this->requests->create($this->brand, requestInput(), $this->admin);
    $this->requests->plan($first);
    $this->requests->confirm($first->fresh(), $this->admin);
    expect($first->fresh()->status)->toBe(CreativeRequest::RUNNING)->and($first->fresh()->confirmed_at)->not->toBeNull()
        ->and($budgets->person($this->admin)['spent'])->toBeGreaterThan(1.8)->and($budgets->company()['alert'])->toBeFalse()
        ->and(Activity::where('log_name', 'requests')->latest('id')->first()->description)->toContain('Confirmed "Whey isolate 20% off weekend" at an estimated $1.81');

    // The second would take the person past $3.
    $second = $this->requests->create($this->brand, requestInput(), $this->admin);
    $this->requests->plan($second);
    expect($budgets->problem($this->admin, (float) $second->fresh()->estimated_cost))->toContain('you have $1.1', 'of your $3.00 monthly budget left');
    try {
        $this->requests->confirm($second->fresh(), $this->admin);
        $this->fail('Confirmed.');
    } catch (ValidationException $e) {
        expect($e->errors()['budget'][0])->toContain('An admin can raise it');
    }

    // Raised for this person, the company's limit is next.
    $budgets->setPersonBudget($this->admin, 50, $this->admin);
    expect($budgets->person($this->admin)['own'])->toBeTrue()->and($budgets->problem($this->admin, 1.81))->toBeNull();
    $this->requests->confirm($second->fresh(), $this->admin);
    $third = $this->requests->create($this->brand, requestInput(), $this->admin);
    $this->requests->plan($third);
    expect($budgets->problem($this->admin, (float) $third->fresh()->estimated_cost))->toContain('the company has $1.3', 'of its $5.00')->and($budgets->company()['alert'])->toBeFalse();

    // Spend records count too, and the alert line.
    $budgets->record('gemini', 'image', 2.5, $this->brand, $this->admin);
    expect($budgets->company()['alert'])->toBeTrue()->and(round($budgets->monthSpend(), 2))->toBe(6.13);
});

it('is public to the team or private to its creator and admins', function () {
    $creator = signInAs(Role::Creator);
    $viewer = signInAs(Role::Viewer);
    fakeLlm(planReply(), planReply());

    $public = $this->requests->create($this->brand, requestInput(), $creator);
    $private = $this->requests->create($this->brand, requestInput(['public' => false]), $creator);

    expect($viewer->can('view', $public))->toBeTrue()->and($viewer->can('view', $private))->toBeFalse()->and($viewer->can('update', $public))->toBeFalse()
        ->and($creator->can('update', $private))->toBeTrue()->and($this->admin->can('view', $private))->toBeTrue()->and($this->admin->can('update', $private))->toBeTrue()
        ->and(CreativeRequest::visibleTo($viewer, $this->brand)->count())->toBe(1)->and(CreativeRequest::visibleTo($this->admin, $this->brand)->count())->toBe(2);

    $this->actingAs($viewer)->get(route('requests.show', [$this->brand, $private]))->assertForbidden();
    $this->actingAs($viewer)->get(route('requests.show', [$this->brand, $public]))->assertOk()->assertDontSee('Change the brief');
    $this->actingAs($viewer)->get(route('requests.create', $this->brand))->assertForbidden();

    $this->requests->setPublic($private, true, $creator);
    expect($viewer->can('view', $private->fresh()))->toBeTrue();
});

it('takes a request from the screen with reference images, shows the questions and the plan, and confirms', function () {
    Queue::fake();
    Livewire::test(NewRequest::class, ['brand' => $this->brand])
        ->set('form.brief', 'Short')->call('save')->assertHasErrors(['form.brief', 'form.placement_ids'])
        ->set('form.brief', 'Marketing image for a sale on whey protein isolate, 20% off this weekend.')
        ->call('tickSet', PlacementPreset::where('name', 'Square and portrait feeds')->sole()->id)
        ->set('productSearch', 'whey')->assertSee('Whey Isolate Plus')->call('chooseVariant', $this->vanilla->id)->assertSee('1 kg · Vanilla')
        ->set('uploads', [UploadedFile::fake()->image('look.jpg', 600, 800), UploadedFile::fake()->image('tub.png', 400, 400)])
        ->set('uploadKinds.1', 'product')
        ->call('save')->assertHasNoErrors()->assertRedirect();

    $request = CreativeRequest::sole();
    expect($request->status)->toBe(CreativeRequest::PLANNING)->and($request->product_variant_id)->toBe($this->vanilla->id)->and(count($request->placement_ids))->toBeGreaterThan(2)
        ->and($request->references->pluck('kind')->all())->toBe(['style', 'product'])->and($request->references[0]->width)->toBe(600)
        ->and(Storage::disk()->exists($request->references[0]->path))->toBeTrue();
    Queue::assertPushed(PlanRequest::class);
    $this->get($request->references[0]->url())->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    // The page polls while planning, then shows the questions.
    $page = Livewire::test(RequestPage::class, ['brand' => $this->brand, 'request' => $request])->assertSee('The planner is reading the brief')->assertSee('wire:poll');
    $request->update(['status' => CreativeRequest::ASKING, 'rounds' => 1, 'questions' => [['id' => 'dates', 'question' => 'Which dates?', 'type' => 'choice', 'options' => [['value' => 'sat', 'label' => 'Saturday'], ['value' => 'sun', 'label' => 'Sunday']], 'allow_other' => true]]]);
    fakeLlm(planReply());
    $page->call('answer')->assertHasErrors('answers.dates')
        ->set('answers.dates', '__other')->set('other.dates', 'Both days')->call('answer')->assertHasNoErrors();
    expect($request->fresh()->answers)->toBe(['dates' => 'Both days'])->and($request->fresh()->status)->toBe(CreativeRequest::PLANNING);

    // Run the plan now, then confirm from the page.
    (new PlanRequest($request->fresh()))->handle(app(Planner::class));
    $page = Livewire::test(RequestPage::class, ['brand' => $this->brand, 'request' => $request->fresh()])->assertSee('The plan')->assertSee('20% off whey isolate')->assertSee('Confirm and run')->assertSee('Estimated cost of the run');
    Bus::fake([GenerateImage::class]);
    $page->call('confirm')->assertDispatched('saved');
    expect($request->fresh()->status)->toBe(CreativeRequest::RUNNING);
    $page->assertDontSee('Confirm and run');

    Livewire::test(RequestsIndex::class, ['brand' => $this->brand])->assertSee('Whey isolate 20% off weekend')->assertSee('Generating');
});

it('refuses reference files that aren\'t images', function () {
    $request = $this->requests->create($this->brand, requestInput(), $this->admin);
    app(RequestReferences::class)->add($request, UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'), 'style');
})->throws(ValidationException::class, 'JPG, PNG or WebP');

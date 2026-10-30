<?php

use App\Auth\Role;
use App\Images\ImageException;
use App\Images\ImageGenerator;
use App\Images\ImageModels;
use App\Images\ImageRequest;
use App\Images\ReferenceImage;
use App\Livewire\Models\ModelsPage;
use App\Llm\LlmException;
use App\Llm\LlmManager;
use App\Llm\LlmRequest;
use App\Models\Connection;
use App\Models\ImageModel;
use App\Models\LlmModel;
use App\Plugins\PluginStates;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = signInAs(Role::Admin);
    $this->models = app(ImageModels::class);
});

it('adds each provider\'s starting models once, with prices, and leaves edited rows alone', function () {
    $added = $this->models->addStarters($this->admin);

    expect($added)->toBeGreaterThanOrEqual(7)
        ->and(ImageModel::count())->toBe($added)
        ->and(ImageModel::where('provider', 'gemini')->count())->toBe(4)
        ->and(ImageModel::where('provider', 'openai')->count())->toBe(3)
        ->and(Activity::where('log_name', 'models')->sole()->description)->toBe("Added the starting image models ({$added})");

    $banana = ImageModel::findByKey('gemini/gemini-nano-banana-2.1');
    expect($banana->label)->toBe('Nano Banana 2.1')->and($banana->enabled)->toBeTrue()
        ->and($banana->price('1K'))->toBe(0.0336)->and($banana->price('4K'))->toBe(0.113)->and($banana->price('8K'))->toBeNull()
        ->and($banana->takesRatio('4:5'))->toBeTrue()->and($banana->takesRatio('1.91:1'))->toBeFalse()
        ->and($banana->closestRatio('1.91:1'))->toBe('16:9')->and($banana->closestRatio('2:1'))->toBe('16:9')->and($banana->closestRatio('1:2'))->toBe('9:16')
        // Opus-level usage: 1,120 output tokens at $30 per million is the 1K price.
        ->and(round($banana->cost(100, 0, 1120), 4))->toBe(0.0338);

    $gpt = ImageModel::findByKey('openai/gpt-image-2');
    expect($gpt->price('1K', 'medium'))->toBe(0.053)->and($gpt->price('2K', 'high'))->toBe(0.844)->and($gpt->max_references)->toBe(16)->and($gpt->transparent)->toBeTrue()
        ->and($gpt->estimate(new ImageRequest('x', '4:5', '1K', 'low', count: 3)))->toEqualWithDelta(0.018, 0.000001)
        ->and($gpt->estimate(new ImageRequest('x')))->toBe(0.053)
        ->and(ImageModel::findByKey('openai/gpt-image-2.5-sunburst')->enabled)->toBeFalse();

    // Only what's on is offered, and starters never overwrite an edit.
    $banana->update(['prices' => ['1K' => 0.05]]);
    $this->models->enable($gpt, false, $this->admin);
    expect($this->models->addStarters($this->admin))->toBe(0)
        ->and($banana->fresh()->price('1K'))->toBe(0.05)
        ->and(ImageModel::options())->toHaveKey('gemini/gemini-nano-banana-2.1')->not->toHaveKey('openai/gpt-image-2')
        ->and($this->models->available()->map->key()->all())->not->toContain('openai/gpt-image-2');

    // A switched-off plugin takes its models with it.
    app(PluginStates::class)->set('gemini', false, $this->admin);
    expect($this->models->available()->map->key()->all())->not->toContain('gemini/gemini-nano-banana-2.1');
});

it('saves a model row with its shapes, sizes, qualities and price table, and refuses what makes no sense', function () {
    $input = ['provider' => 'openai', 'model' => 'gpt-image-3', 'label' => 'GPT Image 3', 'aspect_ratios' => ['1:1', '4:5'], 'sizes' => ['1K', '2K'], 'qualities' => 'Low, High, low',
        'max_references' => 4, 'edits' => true, 'transparent' => false, 'renders_text' => true,
        'prices' => ['low 1K' => '0.01', 'high 1K' => '0.2', 'low 2K' => '', 'junk' => '1'], 'input_price' => 5, 'image_input_price' => 8, 'output_price' => 30, 'notes' => ' New. '];

    $model = $this->models->save(null, $input, $this->admin);
    expect($model->qualities)->toBe(['low', 'high'])->and($model->prices)->toBe(['low 1K' => 0.01, 'high 1K' => 0.2, 'junk' => 1])
        ->and($model->notes)->toBe('New.')->and($model->position)->toBe(10)
        ->and(ImageModels::priceKeys($model->sizes, $model->qualities))->toBe(['low 1K', 'low 2K', 'high 1K', 'high 2K']);

    $refused = function (array $more, string $field, string $message) use ($input) {
        try {
            $this->models->save(null, [...$input, 'model' => 'other', ...$more], $this->admin);
            $this->fail('Accepted.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($field)->and($e->errors()[$field][0])->toContain($message);
        }
    };
    $refused(['provider' => 'nope'], 'provider', 'installed providers');
    $refused(['model' => 'gpt-image-3'], 'model', 'already has that model');
    $refused(['model' => 'has space'], 'model', 'provider\'s own');
    $refused(['aspect_ratios' => []], 'aspect_ratios', 'at least one shape');
    $refused(['sizes' => ['8K']], 'sizes.0', 'invalid');

    // Edits need somewhere to put the reference images.
    expect($this->models->save($model, [...$input, 'max_references' => 0, 'edits' => true], $this->admin)->edits)->toBeFalse();

    $this->models->delete($model, $this->admin);
    expect(ImageModel::count())->toBe(0);
});

it('checks a request against the model before anything is sent', function () {
    $this->models->addStarters($this->admin);
    $banana = ImageModel::findByKey('gemini/gemini-nano-banana-2.1');
    $ref = new ReferenceImage('bytes', 'image/png');

    expect($this->models->check($banana, new ImageRequest('x', '4:5')))->toBe('4:5')
        ->and($this->models->check($banana, new ImageRequest('x', '1.91:1')))->toBe('16:9');

    $refused = function (ImageRequest $request, string $field) use ($banana) {
        try {
            $this->models->check($banana, $request);
            $this->fail('Accepted.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($field);
        }
    };
    $refused(new ImageRequest('x', '9:1'), 'aspect_ratio');
    $refused(new ImageRequest('x', '1:1', '8K'), 'size');
    $refused(new ImageRequest('x', '1:1', '1K', 'high'), 'quality');
    $refused(new ImageRequest('x', '1:1', references: array_fill(0, 11, $ref)), 'references');
    $refused(new ImageRequest('x', '1:1', transparent: true), 'transparent');
    $refused(new ImageRequest('x', '1:1', count: 0), 'count');
});

it('needs a working connection for the model\'s provider, the brand\'s own first', function () {
    $this->models->addStarters($this->admin);
    $banana = ImageModel::findByKey('gemini/gemini-nano-banana-2.1');
    $brand = makeBrand(['name' => 'BioSteel']);
    $generator = app(ImageGenerator::class);
    $request = new ImageRequest('A bottle', '4:5');

    expect($generator->problem($banana, $brand))->toBe('No Google Gemini connection for BioSteel. Add one under Connections.')
        ->and($generator->estimate($banana, $request))->toBe(0.0336);

    $shared = Connection::factory()->create(['plugin' => 'gemini', 'name' => 'Shared Gemini', 'brand_id' => null, 'secrets' => ['api_key' => 'k'], 'status' => Connection::STATUS_ERROR, 'last_error' => 'Google refused the API key.']);
    expect($generator->problem($banana, $brand))->toContain('"Shared Gemini" isn\'t working: Google refused the API key.');

    $shared->update(['status' => Connection::STATUS_CONNECTED, 'last_error' => null]);
    expect($generator->problem($banana, $brand))->toBeNull();

    $own = Connection::factory()->create(['plugin' => 'gemini', 'name' => 'BioSteel Gemini', 'brand_id' => $brand->id, 'secrets' => ['api_key' => 'k2'], 'status' => Connection::STATUS_PENDING]);
    expect($generator->problem($banana, $brand))->toContain('"BioSteel Gemini" isn\'t working: it hasn\'t been checked yet')
        ->and($generator->problem($banana))->toBeNull();

    $this->models->enable($banana, false, $this->admin);
    expect($generator->problem($banana))->toBe('Nano Banana 2.1 is switched off on the Models page.');

    app(PluginStates::class)->set('gemini', false, $this->admin);
    try {
        $generator->generate($banana->fresh(), $request, $brand);
        $this->fail('Generated.');
    } catch (ImageException $e) {
        expect($e->getMessage())->toContain('gemini plugin isn\'t installed or is switched off');
    }
});

it('is admins only, and lets them add starters, edit models and prices and switch text models off from the page', function () {
    $page = Livewire::test(ModelsPage::class)->assertSee('Add the starting models')->assertSee('No connection yet')
        ->call('addStarters')->assertSee('Nano Banana 2.1')->assertSee('GPT Image 2')->assertSee('Add any missing starters');
    $banana = ImageModel::findByKey('gemini/gemini-nano-banana-2.1');

    $page->call('openImage', $banana->id)->assertSet('imageForm.model', 'gemini-nano-banana-2.1')->assertSet('imageForm.prices.2K', 0.0504)
        ->set('imageForm.prices.2K', 'lots')->call('saveImage')->assertHasErrors('imageForm.prices.2K')
        ->set('imageForm.prices.2K', '0.06')->set('imageForm.label', 'Yellow fruit')->call('saveImage')->assertHasNoErrors()->assertSet('imageOpen', null)->assertSee('Yellow fruit');
    expect($banana->fresh()->price('2K'))->toBe(0.06);

    $page->call('openImage', null, 'openai')->assertSet('imageForm.provider', 'openai')->set('imageForm.model', 'gpt-image-3')->set('imageForm.label', 'GPT Image 3')
        ->set('imageForm.qualities', 'low, high')->set('imageForm.sizes', ['1K'])->call('saveImage')->assertHasNoErrors()->assertSee('GPT Image 3');

    $page->call('toggleImage', $banana->id);
    expect($banana->fresh()->enabled)->toBeFalse();

    $page->call('askRemove', $banana->id)->assertSee('Yes, remove')->call('keep')->assertDontSee('Yes, remove')
        ->call('askRemove', $banana->id)->call('remove', $banana->id)->assertDontSee('Yellow fruit');
    expect(ImageModel::find($banana->id))->toBeNull();

    $opus = LlmModel::where('model', 'claude-opus-5-5')->sole();
    $page->call('openLlm', $opus->id)->assertSet('llmForm.input_price', '4')->set('llmForm.input_price', '-1')->call('saveLlm')->assertHasErrors('llmForm.input_price')
        ->set('llmForm.input_price', '4.5')->call('saveLlm')->assertHasNoErrors();
    $page->call('toggleLlm', $opus->id);
    expect($opus->fresh()->input_price)->toBe(4.5)->and($opus->fresh()->enabled)->toBeFalse()->and(LlmModel::options())->not->toHaveKey('claude-opus-5-5');

    signInAs(Role::Creator);
    $this->get(route('models'))->assertForbidden();
});

it('refuses to use a text model that is switched off', function () {
    fakeLlm('hi');
    app(ImageModels::class)->enableLlmModel(LlmModel::where('model', 'claude-opus-5-5')->sole(), false, $this->admin);

    app(LlmManager::class)->complete(new LlmRequest('claude-opus-5-5', 'x', [['role' => 'user', 'content' => 'Hi']]));
})->throws(LlmException::class, 'switched off on the Models page');

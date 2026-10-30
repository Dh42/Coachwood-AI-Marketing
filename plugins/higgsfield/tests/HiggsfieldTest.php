<?php

use App\Auth\Role;
use App\Images\ImageException;
use App\Images\ImageGenerator;
use App\Images\ImageModels;
use App\Images\ImageRequest;
use App\Images\ReferenceImage;
use App\Models\Connection;
use App\Models\ImageModel;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Coachwood\Plugins\Higgsfield\HiggsfieldPlugin;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    $this->admin = signInAs(Role::Admin);
    app(ImageModels::class)->addStarters($this->admin);
    $this->connection = Connection::factory()->create(['plugin' => 'higgsfield', 'name' => 'Higgsfield', 'brand_id' => null,
        'config' => ['key_id' => 'kid-123'], 'secrets' => ['key_secret' => 'ksecret-456'], 'status' => Connection::STATUS_CONNECTED]);
    $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAADElEQVQIW2P4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
});

/**
 * Fakes Higgsfield: the estimate, uploads, submits (one request ID each)
 * and the status of each call in turn.
 *
 * @param  list<array<string, mixed>>  $statuses  What each status check returns, in order.
 */
function fakeHiggsfield(string $png, array $statuses, ?array $estimate = ['type' => 'estimate', 'usd' => '0.040'], array|int|null $submit = null): void
{
    $submits = 0;
    Http::fake([
        'api.higgsfield.ai/estimate/*' => $estimate === null ? Http::response(['detail' => 'nope'], 500) : Http::response($estimate),
        'api.higgsfield.ai/files/generate-upload-url' => function () {
            static $n = 0;
            $n++;

            return Http::response(['upload_url' => "https://bucket.s3.amazonaws.com/up/{$n}.png?sig=x", 'public_url' => "https://cdn.higgsfield.test/up/{$n}.png",
                'content_type' => 'image/png', 'upload_headers' => ['Content-Type' => 'image/png', 'x-amz-tagging' => 'retention=temporary']]);
        },
        'bucket.s3.amazonaws.com/*' => Http::response('', 200),
        'api.higgsfield.ai/requests/*/cancel' => Http::response('', 202),
        'api.higgsfield.ai/requests/*' => Http::sequence(array_map(fn ($s) => Http::response($s['body'] ?? $s, $s['code'] ?? 200), $statuses)),
        'cdn.higgsfield.test/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        'api.higgsfield.ai/*' => function () use (&$submits, $submit) {
            $submits++;
            if (is_int($submit)) {
                return Http::response(['detail' => 'error'], $submit);
            }
            if (is_array($submit)) {
                return Http::response($submit[0], $submit[1]);
            }
            $id = "00000000-0000-0000-0000-00000000000{$submits}";

            return Http::response(['status' => 'queued', 'request_id' => $id, 'status_url' => "https://api.higgsfield.ai/requests/{$id}/status",
                'cancel_url' => "https://api.higgsfield.ai/requests/{$id}/cancel", 'expires_after' => 3600]);
        },
    ]);
}

function hfDone(string $n = '1'): array
{
    return ['status' => 'completed', 'request_id' => "id-{$n}", 'images' => [['url' => "https://cdn.higgsfield.test/out/{$n}.png"]]];
}

it('submits a call, polls it until the image is ready, downloads it and records what Higgsfield estimated', function () {
    fakeHiggsfield($this->png, [['status' => 'queued'], ['status' => 'in_progress'], hfDone()]);
    $model = ImageModel::findByKey('higgsfield/alibaba/qwen-image-3/text-to-image');

    $result = app(ImageGenerator::class)->generate($model, new ImageRequest('A bottle on a bench', '4:5', '2K'));

    expect($result->images)->toHaveCount(1)->and($result->images[0]->width)->toBe(2)
        // Qwen has no 4:5; 3:4 is the closest and the composer crops the rest.
        ->and($result->aspectRatio)->toBe('3:4')
        ->and($result->cost)->toBe(0.04)->and($result->servedBy)->toBe('alibaba/qwen-image-3/text-to-image');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.higgsfield.ai/alibaba/qwen-image-3/text-to-image'
        && $r->header('Authorization') === ['Key kid-123:ksecret-456']
        && filled($r->header('Idempotency-Key')[0] ?? null) && $r->header('Hf-Expires-After') === ['3600']
        && $r->data() === ['prompt' => 'A bottle on a bench', 'aspect_ratio' => '3:4', 'resolution' => '2k', 'prompt_extend' => false, 'enable_thinking' => false]);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.higgsfield.ai/estimate/alibaba/qwen-image-3/text-to-image' && $r->data()['resolution'] === '2k');
    // Polled three times, waiting two seconds, then three, then four and a half.
    Sleep::assertSleptTimes(3);
    Sleep::assertSequence([Sleep::for(2000)->milliseconds(), Sleep::for(3000)->milliseconds(), Sleep::for(4500)->milliseconds()]);
});

it('uploads reference images first and sends their links, on the edit endpoint where the model has one', function () {
    fakeHiggsfield($this->png, [hfDone()]);
    $model = ImageModel::findByKey('higgsfield/alibaba/qwen-image-3/text-to-image');
    app(Settings::class)->set('higgsfield.prompts', 'rewrite', SettingScope::Platform, 0, $this->admin);

    app(ImageGenerator::class)->generate($model, new ImageRequest('Put it on a bench', '1:1', references: [
        new ReferenceImage($this->png, 'image/png', 'the product, exactly as it is'), new ReferenceImage($this->png, 'image/png'),
    ]));

    // Two upload links, two uploads, the estimate, the submit, one status check and the download.
    Http::assertSentCount(8);
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://bucket.s3.amazonaws.com/up/1.png?sig=x'
        && $r->header('x-amz-tagging') === ['retention=temporary'] && $r->body() === $this->png && ! $r->hasHeader('Authorization'));
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.higgsfield.ai/alibaba/qwen-image-3/edit'
        && $r->data()['image_urls'] === ['https://cdn.higgsfield.test/up/1.png', 'https://cdn.higgsfield.test/up/2.png']
        && $r->data()['prompt'] === "Put it on a bench\n\nReference image 1: the product, exactly as it is\nReference image 2"
        && $r->data()['prompt_extend'] === true);
});

it('sends Ideogram its one reference image and its own quality names', function () {
    fakeHiggsfield($this->png, [hfDone()]);
    $model = ImageModel::findByKey('higgsfield/ideogram/v4.0');

    app(ImageGenerator::class)->generate($model, new ImageRequest('Words on a poster', '9:16', references: [new ReferenceImage($this->png, 'image/png')]));

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.higgsfield.ai/ideogram/v4.0'
        && $r->data()['image_url'] === 'https://cdn.higgsfield.test/up/1.png' && $r->data()['rendering_speed'] === 'DEFAULT'
        && ! array_key_exists('resolution', $r->data()));
});

it('makes several images as separate calls', function () {
    fakeHiggsfield($this->png, [hfDone('1'), ['status' => 'in_progress'], hfDone('2')]);
    $model = ImageModel::findByKey('higgsfield/xai/grok-imagine-image-2.0');

    $result = app(ImageGenerator::class)->generate($model, new ImageRequest('x', '1:1', count: 2));

    expect($result->images)->toHaveCount(2)->and($result->cost)->toBe(0.08);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.higgsfield.ai/xai/grok-imagine-image-2.0' && $r->data()['quality'] === 'medium');
});

it('falls back to the model\'s price when Higgsfield can\'t estimate', function () {
    fakeHiggsfield($this->png, [hfDone()], estimate: null);
    $model = ImageModel::findByKey('higgsfield/marketing-studio/image');

    expect(app(ImageGenerator::class)->generate($model, new ImageRequest('x', '1:1', '1K', 'high'))->cost)->toBe(0.221);
});

it('refuses a prompt longer than the model takes before spending anything', function () {
    fakeHiggsfield($this->png, [hfDone()]);
    $model = ImageModel::findByKey('higgsfield/ideogram/v4.0');

    expect(fn () => app(ImageGenerator::class)->generate($model, new ImageRequest(str_repeat('a', 2100))))
        ->toThrow(ImageException::class, 'Ideogram 4.0 (Higgsfield) takes prompts up to 2,048 characters; this one has 2,100.');
    Http::assertNothingSent();
});

it('reports moderation and trademark refusals as blocked, and other failures as worth trying again', function (array $status, string $message, bool $blocked, bool $retryable) {
    fakeHiggsfield($this->png, [$status]);
    $model = ImageModel::findByKey('higgsfield/ideogram/v4.0');

    try {
        app(ImageGenerator::class)->generate($model, new ImageRequest('x'));
        $this->fail('Generated.');
    } catch (ImageException $e) {
        expect($e->getMessage())->toContain($message)->and($e->blocked)->toBe($blocked)->and($e->retryable)->toBe($retryable);
    }
})->with([
    'moderation' => [['status' => 'nsfw'], 'moderation refused', true, false],
    'trademark' => [['status' => 'failed', 'error' => 'ip_detected'], 'names a brand', true, false],
    'failed' => [['status' => 'failed', 'error' => 'Generation failed'], 'Generation failed. Failed calls aren\'t charged', false, true],
    'lost' => [['code' => 404, 'body' => ['detail' => 'Not found']], 'no longer knows the call', false, true],
]);

it('cancels a call that hasn\'t finished in time', function () {
    app(Settings::class)->set('images.timeout_seconds', 30, SettingScope::Platform, 0, $this->admin);
    fakeHiggsfield($this->png, array_fill(0, 20, ['status' => 'queued']));
    $model = ImageModel::findByKey('higgsfield/ideogram/v4.0');

    expect(fn () => app(ImageGenerator::class)->generate($model, new ImageRequest('x')))
        ->toThrow(ImageException::class, 'Higgsfield hadn\'t finished the image after 30 seconds.');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && preg_match('~/requests/[0-9a-f-]+/cancel$~', $r->url()));
});

it('turns API errors into safe messages that never include the key', function (int|array $submit, string $message, bool $retryable, bool $blocked) {
    fakeHiggsfield($this->png, [hfDone()], submit: $submit);
    $model = ImageModel::findByKey('higgsfield/ideogram/v4.0');

    try {
        app(ImageGenerator::class)->generate($model, new ImageRequest('x'));
        $this->fail('Generated.');
    } catch (ImageException $e) {
        expect($e->getMessage())->toContain($message)->not->toContain('ksecret-456')->not->toContain('kid-123')
            ->and($e->retryable)->toBe($retryable)->and($e->blocked)->toBe($blocked);
    }
})->with([
    'bad key' => [401, 'refused the API key on the "Higgsfield" connection', false, false],
    'disabled key' => [[['detail' => 'API key disabled'], 403], 'refused the API key', false, false],
    'no balance' => [[['detail' => 'Insufficient balance'], 403], 'balance is too low', false, false],
    'unknown model' => [404, 'doesn\'t have this model', false, false],
    'queue full' => [429, 'queue for the account is full', true, false],
    'model paused' => [423, 'can\'t take the call right now (423)', true, false],
    'outage' => [503, 'can\'t take the call right now (503)', true, false],
    'bad request' => [[['detail' => "aspect_ratio: '4:5' is not one of ['1:1']"], 400], "Higgsfield returned an error (400). aspect_ratio: '4:5'", false, false],
    'validation list' => [[['detail' => [['msg' => 'prompt too short', 'loc' => ['body', 'prompt']]]], 422], 'prompt too short', false, false],
    'trademark' => [[['detail' => 'ip_detected'], 400], 'names a brand', false, true],
]);

it('picks each model\'s own low, middle or top quality from the setting', function () {
    $plugin = app(HiggsfieldPlugin::class);
    $ideogram = ImageModel::findByKey('higgsfield/ideogram/v4.0');
    $grok = ImageModel::findByKey('higgsfield/xai/grok-imagine-image-2.0');
    $qwen = ImageModel::findByKey('higgsfield/alibaba/qwen-image-3/text-to-image');

    expect($plugin->defaultQuality($ideogram))->toBe('default')->and($plugin->defaultQuality($grok))->toBe('medium')->and($plugin->defaultQuality($qwen))->toBeNull();

    app(Settings::class)->set('higgsfield.quality', 'high', SettingScope::Platform, 0, $this->admin);
    expect($plugin->defaultQuality($ideogram))->toBe('quality')->and($plugin->defaultQuality($grok))->toBe('medium');

    app(Settings::class)->set('higgsfield.quality', 'low', SettingScope::Platform, 0, $this->admin);
    expect($plugin->defaultQuality($ideogram))->toBe('turbo')->and($plugin->defaultQuality($grok))->toBe('low');
});

it('adds its starting models, with the Flare one off until its prices are checked', function () {
    $rows = ImageModel::where('provider', 'higgsfield')->orderBy('position')->get();

    expect($rows->pluck('model')->all())->toBe(['ideogram/v4.0', 'alibaba/qwen-image-3/text-to-image', 'xai/grok-imagine-image-2.0', 'marketing-studio/image', 'marketing-studio/image/flare'])
        ->and($rows->firstWhere('model', 'marketing-studio/image/flare')->enabled)->toBeFalse()
        ->and($rows->firstWhere('model', 'ideogram/v4.0')->price('1K', 'default'))->toBe(0.06);
});

it('lets an admin add another Higgsfield model by its endpoint, which gets the common request shape', function () {
    $row = app(ImageModels::class)->save(null, ['provider' => 'higgsfield', 'model' => 'recraft/v4.1/text-to-image', 'label' => 'Recraft V4.1',
        'aspect_ratios' => ['1:1'], 'sizes' => ['1K'], 'qualities' => '', 'prices' => ['1K' => 0.04]], $this->admin)->fresh();
    fakeHiggsfield($this->png, [hfDone()]);

    app(ImageGenerator::class)->generate($row, new ImageRequest('x'));

    expect($row->key())->toBe('higgsfield/recraft/v4.1/text-to-image')->and(ImageModel::findByKey('higgsfield/recraft/v4.1/text-to-image')->is($row))->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.higgsfield.ai/recraft/v4.1/text-to-image' && $r->data() === ['prompt' => 'x', 'aspect_ratio' => '1:1', 'resolution' => '1k']);
});

it('checks the keys against the models list', function () {
    Http::fake(['api.higgsfield.ai/models' => Http::sequence()->push(['total' => 0, 'items' => []])->push(['detail' => 'Invalid credentials'], 401)]);

    expect(app(HiggsfieldPlugin::class)->healthCheck($this->connection)->message)->toBe('Connected to the Higgsfield API');
    expect(app(HiggsfieldPlugin::class)->healthCheck($this->connection)->message)->toBe('Higgsfield refused the API key.');
    Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Key kid-123:ksecret-456']);
});

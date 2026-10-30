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
use Coachwood\Plugins\OpenAI\OpenAIPlugin;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->admin = signInAs(Role::Admin);
    app(ImageModels::class)->addStarters($this->admin);
    $this->model = ImageModel::findByKey('openai/gpt-image-2');
    $this->connection = Connection::factory()->create(['plugin' => 'openai', 'name' => 'OpenAI', 'brand_id' => null, 'config' => [], 'secrets' => ['api_key' => 'sk-test'], 'status' => Connection::STATUS_CONNECTED]);
    $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAADElEQVQIW2P4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
});

function openaiImage(string $png, int $n = 1): array
{
    return [
        'created' => 1, 'data' => array_fill(0, $n, ['b64_json' => base64_encode($png)]),
        'usage' => ['input_tokens' => 350, 'output_tokens' => 1056, 'total_tokens' => 1406, 'input_tokens_details' => ['text_tokens' => 50, 'image_tokens' => 300]],
    ];
}

it('maps each shape to a pixel size OpenAI takes: multiples of 16, the short edge at the size, wide shapes capped', function () {
    expect(OpenAIPlugin::size('1:1'))->toBe('1024x1024')
        ->and(OpenAIPlugin::size('4:5'))->toBe('1024x1280')
        ->and(OpenAIPlugin::size('9:16'))->toBe('864x1536')
        ->and(OpenAIPlugin::size('16:9'))->toBe('1536x864')
        ->and(OpenAIPlugin::size('1.91:1'))->toBe('1536x800')
        ->and(OpenAIPlugin::size('4:3'))->toBe('1360x1024')
        ->and(OpenAIPlugin::size('1:1', '2K'))->toBe('2048x2048')
        ->and(OpenAIPlugin::size('9:16', '2K'))->toBe('1728x3072');

    // Every mapped size keeps to OpenAI's rules.
    foreach (['1:1', '4:5', '5:4', '9:16', '16:9', '1.91:1', '2:3', '3:2', '4:3', '3:4', '2:1', '1:2', '21:9'] as $ratio) {
        foreach (['1K', '2K'] as $size) {
            [$w, $h] = array_map('intval', explode('x', OpenAIPlugin::size($ratio, $size)));
            expect($w % 16)->toBe(0)->and($h % 16)->toBe(0)->and(max($w, $h) / min($w, $h))->toBeLessThanOrEqual(3)
                ->and($w * $h)->toBeGreaterThanOrEqual(655360)->toBeLessThanOrEqual(8294400)->and(max($w, $h))->toBeLessThanOrEqual(3840);
        }
    }
});

it('generates from a prompt alone with the brand\'s quality, and prices the usage', function () {
    app(Settings::class)->set('openai.quality', 'high', SettingScope::Platform, 0, $this->admin);
    Http::fake(['api.openai.com/v1/images/generations' => Http::response(openaiImage($this->png, 2))]);

    $result = app(ImageGenerator::class)->generate($this->model, new ImageRequest('A bottle', '4:5', count: 2, transparent: true));

    expect($result->images)->toHaveCount(2)->and($result->aspectRatio)->toBe('4:5')->and($result->inputTokens)->toBe(350)->and($result->outputTokens)->toBe(1056)
        // 50 text tokens at $5, 300 image tokens at $8, 1,056 output tokens at $30, per million.
        ->and(round($result->cost, 5))->toBe(0.03433);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-test')
        && $request['model'] === 'gpt-image-2' && $request['size'] === '1024x1280' && $request['quality'] === 'high' && $request['n'] === 2
        && $request['background'] === 'transparent' && $request['output_format'] === 'png' && $request['prompt'] === 'A bottle');
});

it('sends reference images to the edits endpoint as a multipart form, with their notes in the prompt', function () {
    Http::fake(['api.openai.com/v1/images/edits' => Http::response(openaiImage($this->png))]);

    $result = app(ImageGenerator::class)->generate($this->model, new ImageRequest('A bottle on a bench', '1:1', quality: 'low',
        references: [new ReferenceImage($this->png, 'image/png', 'the product'), new ReferenceImage($this->png, 'image/jpeg')]));

    expect($result->images)->toHaveCount(1);
    Http::assertSent(function ($request) {
        $files = collect($request->data())->filter(fn ($part) => ($part['name'] ?? '') === 'image[]');
        $prompt = collect($request->data())->firstWhere('name', 'prompt');
        $quality = collect($request->data())->firstWhere('name', 'quality');

        return $request->isMultipart() && $files->count() === 2 && $files->first()['filename'] === 'reference-1.png'
            && $prompt['contents'] === "A bottle on a bench\n\nReference image 1: the product\nReference image 2" && $quality['contents'] === 'low';
    });
});

it('turns API errors into safe messages that never include the key', function (int $status, array $body, string $message, bool $retryable, bool $blocked) {
    Http::fake(['api.openai.com/*' => Http::response($body, $status)]);

    try {
        app(ImageGenerator::class)->generate($this->model, new ImageRequest('x'));
        $this->fail('Generated.');
    } catch (ImageException $e) {
        expect($e->getMessage())->toContain($message)->not->toContain('sk-test')->and($e->retryable)->toBe($retryable)->and($e->blocked)->toBe($blocked);
    }
})->with([
    'bad key' => [401, ['error' => ['code' => 'invalid_api_key', 'message' => 'x']], 'refused the API key', false, false],
    'no credit' => [429, ['error' => ['code' => 'insufficient_quota', 'message' => 'x']], 'out of credit', false, false],
    'rate limited' => [429, ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'x']], 'limiting requests', true, false],
    'moderation' => [400, ['error' => ['code' => 'moderation_blocked', 'message' => 'Your request was rejected.']], 'wouldn\'t make this image. Your request was rejected.', false, true],
    'outage' => [500, ['error' => ['code' => 'server_error', 'message' => 'x']], 'server_error', true, false],
    'bad request' => [400, ['error' => ['code' => 'invalid_size', 'message' => 'Bad size.']], 'invalid_size). Bad size.', false, false],
]);

it('checks the key against the models list', function () {
    Http::fake(['api.openai.com/v1/models' => Http::sequence()->push(['data' => []])->push(['error' => []], 401)]);
    expect(app(OpenAIPlugin::class)->healthCheck($this->connection)->message)->toBe('Connected to the OpenAI API');
    expect(app(OpenAIPlugin::class)->healthCheck($this->connection)->message)->toBe('OpenAI refused the API key.');
});

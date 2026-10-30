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
use Coachwood\Plugins\Gemini\GeminiPlugin;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->admin = signInAs(Role::Admin);
    app(ImageModels::class)->addStarters($this->admin);
    $this->model = ImageModel::findByKey('gemini/gemini-nano-banana-2.1');
    $this->connection = Connection::factory()->create(['plugin' => 'gemini', 'name' => 'Gemini', 'brand_id' => null, 'config' => [], 'secrets' => ['api_key' => 'AIza-test'], 'status' => Connection::STATUS_CONNECTED]);
    $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAADElEQVQIW2P4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
});

function geminiImage(string $png, array $extra = []): array
{
    return [
        'candidates' => [['content' => ['parts' => [['text' => 'Here you go.'], ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($png)]]]], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 300, 'candidatesTokenCount' => 1120],
        'modelVersion' => 'gemini-nano-banana-2.1-001',
        ...$extra,
    ];
}

it('sends the prompt, the shape, the size and the reference images to generateContent, and prices the usage', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiImage($this->png))]);

    $result = app(ImageGenerator::class)->generate($this->model, new ImageRequest('A bottle on a bench', '1.91:1', '2K',
        references: [new ReferenceImage($this->png, 'image/png', 'the product, exactly as it is')], count: 2));

    expect($result->images)->toHaveCount(2)->and($result->images[0]->width)->toBe(2)->and($result->images[0]->mime)->toBe('image/png')
        ->and($result->aspectRatio)->toBe('16:9')->and($result->servedBy)->toBe('gemini-nano-banana-2.1-001')->and($result->text)->toBe('Here you go.')
        ->and($result->inputTokens)->toBe(600)->and($result->outputTokens)->toBe(2240)
        // Two images: 600 prompt tokens at $1.50 and 2,240 output tokens at $30, per million.
        ->and(round($result->cost, 4))->toBe(0.0681);

    Http::assertSentCount(2);
    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/v1beta/models/gemini-nano-banana-2.1:generateContent')
            && $request->header('x-goog-api-key') === ['AIza-test']
            && $body['generationConfig'] === ['responseModalities' => ['TEXT', 'IMAGE'], 'imageConfig' => ['aspectRatio' => '16:9', 'imageSize' => '2K']]
            && $body['contents'][0]['parts'][0] === ['text' => 'A bottle on a bench']
            && $body['contents'][0]['parts'][1] === ['text' => 'Reference image 1: the product, exactly as it is']
            && $body['contents'][0]['parts'][2]['inline_data']['mime_type'] === 'image/png';
    });
});

it('uses the configured API version', function () {
    app(Settings::class)->set('gemini.api_version', 'v1', SettingScope::Platform, 0, $this->admin);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiImage($this->png))]);

    app(ImageGenerator::class)->generate($this->model, new ImageRequest('x'));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/models/'));
});

it('reports a refused picture as blocked, with Google\'s reason', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'I can\'t show that.']]], 'finishReason' => 'IMAGE_SAFETY']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]])]);

    try {
        app(ImageGenerator::class)->generate($this->model, new ImageRequest('x'));
        $this->fail('Generated.');
    } catch (ImageException $e) {
        expect($e->blocked)->toBeTrue()->and($e->getMessage())->toBe('Google wouldn\'t make this image. I can\'t show that.');
    }
});

it('turns API errors into safe messages that never include the key', function (int $status, array $body, string $message, bool $retryable) {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body, $status)]);

    try {
        app(ImageGenerator::class)->generate($this->model, new ImageRequest('x'));
        $this->fail('Generated.');
    } catch (ImageException $e) {
        expect($e->getMessage())->toContain($message)->not->toContain('AIza-test')->and($e->retryable)->toBe($retryable);
    }
})->with([
    'bad key' => [400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'API key not valid.']], 'refused the API key', false],
    'forbidden' => [403, ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'x']], 'refused the API key', false],
    'rate limited' => [429, ['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'x']], 'limiting requests', true],
    'unknown model' => [404, ['error' => ['status' => 'NOT_FOUND', 'message' => 'x']], 'know this model', false],
    'outage' => [503, ['error' => ['status' => 'UNAVAILABLE', 'message' => 'x']], 'UNAVAILABLE', true],
    'bad request' => [400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Unsupported size.']], 'Unsupported size.', false],
]);

it('checks the key against the models list', function () {
    Http::fake(['generativelanguage.googleapis.com/v1beta/models*' => Http::sequence()->push(['models' => []])->push(['error' => ['status' => 'INVALID_ARGUMENT']], 400)]);
    expect(app(GeminiPlugin::class)->healthCheck($this->connection)->message)->toBe('Connected to the Gemini API');
    expect(app(GeminiPlugin::class)->healthCheck($this->connection)->message)->toContain('refused the API key');
});

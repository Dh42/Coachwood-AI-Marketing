<?php

use App\Auth\Role;
use App\Catalogue\CutoutException;
use App\Models\Connection;
use App\Plugins\Connections;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Coachwood\Plugins\Photoroom\PhotoroomPlugin;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->admin = signInAs(Role::Admin);
    $this->connection = Connection::factory()->create(['plugin' => 'photoroom', 'name' => 'Photoroom', 'brand_id' => null, 'config' => [], 'secrets' => ['api_key' => 'pr-test'], 'status' => Connection::STATUS_CONNECTED]);
    $this->plugin = app(PhotoroomPlugin::class);
    $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAADElEQVQIW2P4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
});

it('sends the photo to the segment call with the key, the size and cropping, and prices the cut-out from the setting', function () {
    Http::fake(['sdk.photoroom.com/*' => Http::response($this->png, 200, ['Content-Type' => 'image/png'])]);

    $cutout = $this->plugin->removeBackground($this->connection, 'jpeg-bytes', 'image/jpeg');

    expect($cutout->png)->toBe($this->png)->and($cutout->cost)->toBe(0.02);
    Http::assertSent(function ($request) {
        $parts = collect($request->data())->keyBy('name');

        return $request->url() === 'https://sdk.photoroom.com/v1/segment'
            && $request->header('x-api-key') === ['pr-test']
            && $request->hasFile('image_file', 'jpeg-bytes', 'photo.jpg')
            && $parts['format']['contents'] === 'png' && $parts['size']['contents'] === 'full' && $parts['crop']['contents'] === 'true';
    });

    // The settings change the size and the price; a JSON reply with the picture in base64 is read too.
    app(Settings::class)->set('photoroom.size', 'hd', SettingScope::Platform, 0, $this->admin);
    app(Settings::class)->set('photoroom.price_per_image', 0.05, SettingScope::Platform, 0, $this->admin);
    Http::fake(['sdk.photoroom.com/*' => Http::response(['base64img' => base64_encode($this->png)], 200, ['Content-Type' => 'application/json'])]);
    $cutout = $this->plugin->removeBackground($this->connection, 'png-bytes', 'image/png');
    expect($cutout->png)->toBe($this->png)->and($cutout->cost)->toBe(0.05);
    Http::assertSent(fn ($request) => collect($request->data())->keyBy('name')['size']['contents'] === 'hd' && $request->hasFile('image_file', 'png-bytes', 'photo.png'));
});

it('turns Photoroom\'s errors into safe messages that never include the key', function (int $status, array|string $body, string $message, bool $retryable) {
    Http::fake(['sdk.photoroom.com/*' => Http::response($body, $status, is_array($body) ? ['Content-Type' => 'application/json'] : [])]);

    try {
        $this->plugin->removeBackground($this->connection, 'x', 'image/png');
        $this->fail('Returned a cut-out.');
    } catch (CutoutException $e) {
        expect($e->getMessage())->toBe($message)->and($e->retryable)->toBe($retryable)->and($e->getMessage())->not->toContain('pr-test');
    }
})->with([
    [403, '', 'Photoroom refused the API key on the "Photoroom" connection. Check it under Connections.', false],
    [402, ['detail' => 'Out of credits', 'status_code' => 402, 'type' => 'payment'], 'The Photoroom account has no images left this month. Top it up on photoroom.com under API.', false],
    [429, '', 'Photoroom is limiting requests right now. It can be tried again shortly.', true],
    [503, '', 'Photoroom returned an error (503). It can be tried again shortly.', true],
    [400, ['detail' => 'Image too large', 'status_code' => 400, 'type' => 'bad_request'], 'Photoroom returned an error (400). Image too large', false],
    [200, '', 'Photoroom sent back no picture.', false],
]);

it('checks the key by trying the call with no photo, so a refused key shows and a good one costs nothing', function (int $status, bool $ok, string $message) {
    Http::fake(['sdk.photoroom.com/*' => Http::response('', $status)]);

    $result = app(Connections::class)->check($this->connection);

    expect($result->ok)->toBe($ok)->and($result->message)->toBe($message)->and($this->connection->fresh()->isConnected())->toBe($ok);
    Http::assertSent(fn ($request) => $request->header('x-api-key') === ['pr-test'] && ! $request->hasFile('image_file'));
})->with([
    [400, true, 'Connected to the Photoroom API'],
    [422, true, 'Connected to the Photoroom API'],
    [403, false, 'Photoroom refused the API key. Check it on photoroom.com under API.'],
    [402, false, 'The Photoroom account has no images left this month. Top it up on photoroom.com under API.'],
    [500, false, 'Photoroom returned an error (500).'],
]);

it('says when a sandbox key is in use, since its results are watermarked', function () {
    expect($this->plugin->accountLabel($this->connection))->toBe('the Photoroom API');
    $this->connection->update(['secrets' => ['api_key' => 'sandbox_abc']]);
    expect($this->plugin->accountLabel($this->connection))->toBe('the Photoroom API (sandbox key: results are watermarked)');
});

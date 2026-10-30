<?php

namespace Coachwood\Plugins\Photoroom;

use App\Catalogue\Cutout;
use App\Catalogue\CutoutException;
use App\Models\Connection;
use App\Plugins\ConnectionField;
use App\Plugins\ConnectionMethod;
use App\Plugins\Contracts\BackgroundRemover;
use App\Plugins\HealthResult;
use App\Plugins\IntegrationPlugin;
use App\Settings\SettingControl;
use App\Settings\SettingDefinition;
use App\Settings\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Photoroom's Remove Background API (the Basic plan): one photo in, a PNG
 * with a see-through background out, cropped to the product. Photoroom
 * charges per image and doesn't say what a call cost, so the price is a
 * setting and is recorded as spend per cut-out.
 */
class PhotoroomPlugin extends IntegrationPlugin implements BackgroundRemover
{
    private const ENDPOINT = 'https://sdk.photoroom.com/v1/segment';

    public function key(): string
    {
        return 'photoroom';
    }

    public function name(): string
    {
        return 'Photoroom';
    }

    public function description(): string
    {
        return 'Cuts the product out of the store\'s photos, leaving a see-through background, so finished ads can show the real pack shot.';
    }

    public function connectionMethod(): ConnectionMethod
    {
        return ConnectionMethod::Fields;
    }

    public function connectionFields(): array
    {
        return [
            new ConnectionField('api_key', 'API key',
                help: 'From photoroom.com → API → your API key, on the Basic (Remove Background) plan. A key starting "sandbox_" is free but watermarks the result, so it\'s only for trying the connection. Stored encrypted and never shown again.',
                secret: true,
                placeholder: 'A live key, or sandbox_… to try it'),
        ];
    }

    public function settings(): array
    {
        return [
            new SettingDefinition(
                key: 'photoroom.price_per_image',
                label: 'Price per cut-out',
                description: 'What each cut-out is recorded as costing, since Photoroom doesn\'t say per call. The Basic plan works out at about 2 cents an image; check it against photoroom.com/api/pricing.',
                control: SettingControl::Number,
                default: 0.02,
                rules: ['value' => ['required', 'numeric', 'min:0', 'max:10']],
                unit: '$',
                min: 0,
                max: 10,
                section: 'Photoroom',
            ),
            new SettingDefinition(
                key: 'photoroom.size',
                label: 'Cut-out size',
                description: 'How large a cut-out Photoroom sends back. Full keeps the store photo\'s own size (up to 36 megapixels) and is slower; HD is 4 megapixels, plenty for any ad.',
                control: SettingControl::Select,
                default: 'full',
                rules: ['value' => ['required', 'in:full,hd,medium']],
                section: 'Photoroom',
                options: ['full' => 'Full: the photo\'s own size', 'hd' => 'HD: 4 megapixels', 'medium' => 'Medium: 1.5 megapixels'],
                advanced: true,
            ),
        ];
    }

    public function accountLabel(Connection $connection): string
    {
        return str_starts_with((string) $connection->secret('api_key'), 'sandbox_') ? 'the Photoroom API (sandbox key: results are watermarked)' : 'the Photoroom API';
    }

    /**
     * Photoroom has no endpoint that only checks a key on the Basic plan,
     * so the key is tried on the cut-out call with no photo attached: a
     * refused key fails before the missing photo does, and a missing
     * photo costs nothing.
     */
    public function healthCheck(Connection $connection): HealthResult
    {
        try {
            $response = $this->http($connection, 20)->post(self::ENDPOINT, ['format' => 'png']);
        } catch (ConnectionException) {
            return HealthResult::failed('Couldn\'t reach Photoroom.');
        }

        return match (true) {
            in_array($response->status(), [401, 403], true) => HealthResult::failed('Photoroom refused the API key. Check it on photoroom.com under API.'),
            $response->status() === 402 => HealthResult::failed('The Photoroom account has no images left this month. Top it up on photoroom.com under API.'),
            in_array($response->status(), [400, 422], true) => HealthResult::ok('Connected to the Photoroom API'),
            default => HealthResult::failed("Photoroom returned an error ({$response->status()})."),
        };
    }

    public function removeBackground(Connection $connection, string $bytes, string $mime): Cutout
    {
        $settings = app(Settings::class);
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        try {
            $response = $this->http($connection, 120)
                ->attach('image_file', $bytes, "photo.{$extension}", ['Content-Type' => $mime])
                ->post(self::ENDPOINT, ['format' => 'png', 'size' => (string) $settings->get('photoroom.size'), 'crop' => 'true']);
        } catch (ConnectionException) {
            throw new CutoutException('Couldn\'t reach Photoroom. It can be tried again shortly.', retryable: true);
        }

        if ($response->failed()) {
            throw $this->error($response, $connection);
        }

        // A PNG by default; a JSON wrapper with the image in base64 when the account is set that way.
        $png = str_starts_with((string) $response->header('Content-Type'), 'application/json')
            ? base64_decode((string) $response->json('base64img'), true)
            : $response->body();

        if (! $png) {
            throw new CutoutException('Photoroom sent back no picture.');
        }

        return new Cutout($png, max(0, (float) $settings->get('photoroom.price_per_image')));
    }

    private function error(Response $response, Connection $connection): CutoutException
    {
        $status = $response->status();
        $detail = str_starts_with((string) $response->header('Content-Type'), 'application/json') ? trim((string) $response->json('detail')) : '';

        return match (true) {
            in_array($status, [401, 403], true) => new CutoutException("Photoroom refused the API key on the \"{$connection->name}\" connection. Check it under Connections."),
            $status === 402 => new CutoutException('The Photoroom account has no images left this month. Top it up on photoroom.com under API.'),
            $status === 429 => new CutoutException('Photoroom is limiting requests right now. It can be tried again shortly.', retryable: true),
            $status >= 500 => new CutoutException("Photoroom returned an error ({$status}). It can be tried again shortly.", retryable: true),
            default => new CutoutException("Photoroom returned an error ({$status}).".($detail ? " {$detail}" : '')),
        };
    }

    private function http(Connection $connection, int $timeout): PendingRequest
    {
        return Http::withHeaders(['x-api-key' => (string) $connection->secret('api_key')])->timeout($timeout);
    }
}

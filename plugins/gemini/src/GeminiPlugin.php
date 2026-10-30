<?php

namespace Coachwood\Plugins\Gemini;

use App\Images\GeneratedImage;
use App\Images\ImageException;
use App\Images\ImageRequest;
use App\Images\ImageResult;
use App\Models\Brand;
use App\Models\Connection;
use App\Models\ImageModel;
use App\Plugins\ConnectionField;
use App\Plugins\ConnectionMethod;
use App\Plugins\Contracts\ImageProvider;
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
 * Google's Nano Banana image models through the Gemini API's generateContent
 * call: a prompt with reference images in, one image out per call.
 */
class GeminiPlugin extends IntegrationPlugin implements ImageProvider
{
    private const BASE = 'https://generativelanguage.googleapis.com';

    /** The shapes the Gemini image models take (the API's own list, less the banner shapes no placement uses). */
    private const RATIOS = ['1:1', '4:5', '5:4', '9:16', '16:9', '2:3', '3:2', '4:3', '3:4', '21:9'];

    public function key(): string
    {
        return 'gemini';
    }

    public function name(): string
    {
        return 'Google Gemini';
    }

    public function description(): string
    {
        return 'The Nano Banana image models: fast and cheap, or the Pro one for the best text and detail.';
    }

    public function connectionMethod(): ConnectionMethod
    {
        return ConnectionMethod::Fields;
    }

    public function connectionFields(): array
    {
        return [
            new ConnectionField('api_key', 'API key',
                help: 'From Google AI Studio (aistudio.google.com → Get API key), on a project with billing set up. Stored encrypted and never shown again.',
                secret: true,
                placeholder: 'AIza…'),
        ];
    }

    public function settings(): array
    {
        return [new SettingDefinition(
            key: 'gemini.api_version',
            label: 'Gemini API version',
            description: 'The version in every Gemini call\'s address. v1beta has everything the image models need; change it only if Google says to.',
            control: SettingControl::Select,
            default: 'v1beta',
            rules: ['value' => ['required', 'in:v1,v1beta']],
            section: 'Gemini',
            options: ['v1beta' => 'v1beta', 'v1' => 'v1'],
            advanced: true,
        )];
    }

    public function accountLabel(Connection $connection): string
    {
        return 'the Gemini API';
    }

    public function healthCheck(Connection $connection): HealthResult
    {
        $response = $this->http($connection, 20)->get(self::BASE.'/'.$this->version().'/models', ['pageSize' => 1]);

        if (in_array($response->status(), [400, 401, 403], true)) {
            return HealthResult::failed('Google refused the API key. Check it, and that the Gemini API is turned on for its project.');
        }

        if ($response->failed()) {
            return HealthResult::failed("Google returned an error ({$response->status()}).");
        }

        return HealthResult::ok('Connected to the Gemini API');
    }

    /**
     * Google's list prices, October 2026 (standard tier). Output is priced
     * per image by size, which is a fixed number of output tokens.
     */
    public function imageModels(): array
    {
        $sizes = ['1K', '2K', '4K'];

        return [
            ['model' => 'gemini-nano-banana-2.1', 'label' => 'Nano Banana 2.1', 'aspect_ratios' => self::RATIOS, 'sizes' => $sizes, 'qualities' => [],
                'max_references' => 10, 'edits' => true, 'transparent' => false, 'renders_text' => true,
                'prices' => ['1K' => 0.0336, '2K' => 0.0504, '4K' => 0.113], 'input_price' => 1.5, 'image_input_price' => 1.5, 'output_price' => 30,
                'notes' => 'Google\'s recommended model: fast, cheap, good text, up to 10 reference images.', 'enabled' => true, 'position' => 10],
            ['model' => 'gemini-3-pro-image', 'label' => 'Nano Banana Pro', 'aspect_ratios' => self::RATIOS, 'sizes' => $sizes, 'qualities' => [],
                'max_references' => 6, 'edits' => true, 'transparent' => false, 'renders_text' => true,
                'prices' => ['1K' => 0.134, '2K' => 0.134, '4K' => 0.24], 'input_price' => 2, 'image_input_price' => 2, 'output_price' => 120,
                'notes' => 'The highest quality and the best text; slower and about four times the price. Up to 6 reference images.', 'enabled' => true, 'position' => 20],
            ['model' => 'gemini-3.1-flash-image', 'label' => 'Nano Banana 2', 'aspect_ratios' => self::RATIOS, 'sizes' => $sizes, 'qualities' => [],
                'max_references' => 10, 'edits' => true, 'transparent' => false, 'renders_text' => true,
                'prices' => ['1K' => 0.067, '2K' => 0.101, '4K' => 0.151], 'input_price' => 0.5, 'image_input_price' => 0.5, 'output_price' => 60,
                'notes' => 'The model before Nano Banana 2.1, which Google recommends instead.', 'enabled' => false, 'position' => 30],
            ['model' => 'gemini-3.1-flash-lite-image', 'label' => 'Nano Banana 2 Lite', 'aspect_ratios' => self::RATIOS, 'sizes' => ['1K'], 'qualities' => [],
                'max_references' => 4, 'edits' => true, 'transparent' => false, 'renders_text' => false,
                'prices' => ['1K' => 0.0336], 'input_price' => 0.25, 'image_input_price' => 0.25, 'output_price' => 30,
                'notes' => 'The cheapest to send prompts to, 1K only, not made for editing or several references.', 'enabled' => false, 'position' => 40],
        ];
    }

    public function defaultQuality(ImageModel $model, ?Brand $brand = null): ?string
    {
        return null;
    }

    public function generateImages(Connection $connection, ImageModel $model, ImageRequest $request): ImageResult
    {
        $parts = [['text' => $request->prompt]];
        foreach ($request->references as $i => $reference) {
            $parts[] = ['text' => 'Reference image '.($i + 1).($reference->note ? ": {$reference->note}" : '')];
            $parts[] = ['inline_data' => ['mime_type' => $reference->mime, 'data' => $reference->base64()]];
        }

        $body = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
                'imageConfig' => ['aspectRatio' => $request->aspectRatio, 'imageSize' => $request->size],
            ],
        ];

        $images = [];
        $text = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $servedBy = $model->model;

        // One image per call.
        for ($n = 0; $n < max(1, $request->count); $n++) {
            $data = $this->send($connection, $model, $body);

            $servedBy = $data['modelVersion'] ?? $servedBy;
            $inputTokens += (int) ($data['usageMetadata']['promptTokenCount'] ?? 0);
            $outputTokens += (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0);

            $candidate = $data['candidates'][0] ?? null;
            $found = false;
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (isset($part['inlineData']['data'])) {
                    $images[] = GeneratedImage::fromBytes(base64_decode($part['inlineData']['data']), $part['inlineData']['mimeType'] ?? null);
                    $found = true;
                } elseif (filled($part['text'] ?? null)) {
                    $text[] = trim($part['text']);
                }
            }

            if (! $found) {
                $reason = $data['promptFeedback']['blockReason'] ?? $candidate['finishReason'] ?? null;
                $why = implode(' ', $text) ?: ($reason ? "Google gave the reason {$reason}." : 'Google sent no image and no reason.');

                throw $reason && $reason !== 'STOP'
                    ? ImageException::blocked("Google wouldn't make this image. {$why}")
                    : new ImageException("Google sent no image. {$why}");
            }
        }

        // Google prices text and image input alike, so every prompt token goes at the input price.
        $cost = $model->cost($inputTokens, 0, $outputTokens);

        return new ImageResult($images, $inputTokens, $outputTokens, $cost, $servedBy, text: implode("\n", array_unique($text)));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function send(Connection $connection, ImageModel $model, array $body): array
    {
        try {
            $response = $this->http($connection, (int) app(Settings::class)->get('images.timeout_seconds'))
                ->post(self::BASE.'/'.$this->version()."/models/{$model->model}:generateContent", $body);
        } catch (ConnectionException) {
            throw new ImageException('Couldn\'t reach Google. The run can be tried again shortly.', retryable: true);
        }

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        throw $this->error($response, $connection);
    }

    private function error(Response $response, Connection $connection): ImageException
    {
        $status = $response->status();
        $reason = $response->json('error.status') ?? "HTTP {$status}";
        $message = (string) $response->json('error.message');

        if (in_array($status, [401, 403], true) || str_contains($message, 'API key')) {
            return new ImageException("Google refused the API key on the \"{$connection->name}\" connection. Check it under Connections.");
        }

        if ($status === 429) {
            return new ImageException('Google is limiting requests right now. The run can be tried again shortly.', retryable: true);
        }

        if ($status === 404) {
            return new ImageException('Google doesn\'t know this model. Check the model ID on the Models page.');
        }

        if ($status >= 500) {
            return new ImageException("Google returned an error ({$reason}). The run can be tried again shortly.", retryable: true);
        }

        return new ImageException("Google returned an error ({$reason}).".($message ? ' '.$message : ''));
    }

    private function http(Connection $connection, int $timeout): PendingRequest
    {
        return Http::withHeaders(['x-goog-api-key' => (string) $connection->secret('api_key')])->acceptJson()->timeout(max(10, $timeout));
    }

    private function version(): string
    {
        return (string) app(Settings::class)->get('gemini.api_version');
    }
}

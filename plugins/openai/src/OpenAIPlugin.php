<?php

namespace Coachwood\Plugins\OpenAI;

use App\Images\GeneratedImage;
use App\Images\ImageException;
use App\Images\ImageRequest;
use App\Images\ImageResult;
use App\Images\ImageSize;
use App\Models\Brand;
use App\Models\Connection;
use App\Models\ImageModel;
use App\Platforms\AspectRatio;
use App\Plugins\ConnectionField;
use App\Plugins\ConnectionMethod;
use App\Plugins\Contracts\ImageProvider;
use App\Plugins\HealthResult;
use App\Plugins\IntegrationPlugin;
use App\Settings\SettingControl;
use App\Settings\SettingDefinition;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI's GPT Image models through the Images API: generations for a
 * prompt alone, edits when reference images come with it.
 */
class OpenAIPlugin extends IntegrationPlugin implements ImageProvider
{
    private const BASE = 'https://api.openai.com/v1';

    /** GPT Image 2 takes any size between 1:3 and 3:1 with edges in multiples of 16; these are the shapes placements use. */
    private const RATIOS = ['1:1', '4:5', '5:4', '9:16', '16:9', '1.91:1', '2:3', '3:2', '4:3', '3:4', '2:1', '1:2', '21:9'];

    /** The short edge for each size, and the longest the long edge may be. */
    private const EDGES = ['1K' => [1024, 1536], '2K' => [2048, 3072]];

    public function key(): string
    {
        return 'openai';
    }

    public function name(): string
    {
        return 'OpenAI';
    }

    public function description(): string
    {
        return 'The GPT Image models: up to 16 reference images in one request, strong text, any shape.';
    }

    public function connectionMethod(): ConnectionMethod
    {
        return ConnectionMethod::Fields;
    }

    public function connectionFields(): array
    {
        return [
            new ConnectionField('api_key', 'API key',
                help: 'From platform.openai.com → API keys, on a project with credit. Stored encrypted and never shown again.',
                secret: true,
                placeholder: 'sk-…'),
        ];
    }

    public function settings(): array
    {
        return [new SettingDefinition(
            key: 'openai.quality',
            label: 'OpenAI image quality',
            description: 'How carefully the GPT Image models draw when a request doesn\'t say. Higher is slower and costs more: at 1K, low is about half a cent, medium about 5 cents, high about 20 cents.',
            control: SettingControl::Select,
            default: 'medium',
            scopes: [SettingScope::Platform, SettingScope::Brand],
            rules: ['value' => ['required', 'in:low,medium,high']],
            section: 'OpenAI',
            options: ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'],
        )];
    }

    public function accountLabel(Connection $connection): string
    {
        return 'the OpenAI API';
    }

    public function healthCheck(Connection $connection): HealthResult
    {
        $response = $this->http($connection, 20)->get(self::BASE.'/models');

        if ($response->status() === 401 || $response->status() === 403) {
            return HealthResult::failed('OpenAI refused the API key.');
        }

        if ($response->failed()) {
            return HealthResult::failed("OpenAI returned an error ({$response->status()}).");
        }

        return HealthResult::ok('Connected to the OpenAI API');
    }

    /**
     * OpenAI's list prices, October 2026. Output is priced per token, so the
     * per-image figures are OpenAI's own estimates at its three standard
     * sizes; 2K is four times 1K until checked against real usage.
     */
    public function imageModels(): array
    {
        $prices = fn (array $perK) => collect($perK)->flatMap(fn ($p, $q) => ["{$q} 1K" => $p, "{$q} 2K" => round($p * 4, 4)])->all();
        $base = ['aspect_ratios' => self::RATIOS, 'sizes' => ['1K', '2K'], 'max_references' => 16, 'edits' => true, 'transparent' => true, 'renders_text' => true,
            'input_price' => 5, 'image_input_price' => 8, 'output_price' => 30];

        return [
            [...$base, 'model' => 'gpt-image-2', 'label' => 'GPT Image 2', 'qualities' => ['low', 'medium', 'high'],
                'prices' => $prices(['low' => 0.006, 'medium' => 0.053, 'high' => 0.211]),
                'notes' => 'Up to 16 reference images, strong text, any shape. 2K prices are estimates until checked.', 'enabled' => true, 'position' => 10],
            [...$base, 'model' => 'gpt-image-2.5-sunburst', 'label' => 'GPT Image 2.5 Sunburst', 'qualities' => ['low', 'medium', 'high', 'xhigh', 'max'],
                'prices' => $prices(['low' => 0.006, 'medium' => 0.053, 'high' => 0.211]),
                'notes' => 'Listed in OpenAI\'s API reference (September 2026) with the same token prices as GPT Image 2. Per-image prices copied from GPT Image 2; the xhigh and max qualities aren\'t priced here yet. Check before switching on.', 'enabled' => false, 'position' => 20],
            [...$base, 'model' => 'gpt-image-2.5-flare', 'label' => 'GPT Image 2.5 Flare', 'qualities' => ['low', 'medium', 'high', 'xhigh', 'max'],
                'prices' => $prices(['low' => 0.006, 'medium' => 0.053, 'high' => 0.211]),
                'notes' => 'Listed in OpenAI\'s API reference (September 2026) with the same token prices as GPT Image 2. Per-image prices copied from GPT Image 2; the xhigh and max qualities aren\'t priced here yet. Check before switching on.', 'enabled' => false, 'position' => 30],
        ];
    }

    public function defaultQuality(ImageModel $model, ?Brand $brand = null): ?string
    {
        $quality = (string) app(Settings::class)->get('openai.quality', $brand?->id);

        return $model->takesQuality($quality) ? $quality : ($model->qualities[0] ?? null);
    }

    public function generateImages(Connection $connection, ImageModel $model, ImageRequest $request): ImageResult
    {
        $quality = $request->quality ?? $this->defaultQuality($model);

        $fields = [
            'model' => $model->model,
            'prompt' => $request->prompt,
            'n' => max(1, $request->count),
            'size' => self::size($request->aspectRatio, $request->size),
            'quality' => $quality,
            'output_format' => 'png',
            'background' => $request->transparent ? 'transparent' : 'auto',
        ];

        $timeout = (int) app(Settings::class)->get('images.timeout_seconds');

        try {
            if ($request->references === []) {
                $response = $this->http($connection, $timeout)->post(self::BASE.'/images/generations', $fields);
            } else {
                $http = $this->http($connection, $timeout)->asMultipart();
                foreach ($request->references as $i => $reference) {
                    $http = $http->attach('image[]', $reference->data, 'reference-'.($i + 1).'.'.self::extension($reference->mime), ['Content-Type' => $reference->mime]);
                }
                $response = $http->post(self::BASE.'/images/edits', collect([...$fields, 'prompt' => self::promptWithNotes($request)])->map(fn ($v) => (string) $v)->all());
            }
        } catch (ConnectionException) {
            throw new ImageException('Couldn\'t reach OpenAI. The run can be tried again shortly.', retryable: true);
        }

        if ($response->failed()) {
            throw $this->error($response, $connection);
        }

        $images = [];
        foreach ($response->json('data') ?? [] as $item) {
            if (filled($item['b64_json'] ?? null)) {
                $images[] = GeneratedImage::fromBytes(base64_decode($item['b64_json']), 'image/png');
            }
        }

        if ($images === []) {
            throw new ImageException('OpenAI sent no image and no reason.');
        }

        $usage = $response->json('usage') ?? [];
        $textIn = (int) ($usage['input_tokens_details']['text_tokens'] ?? $usage['input_tokens'] ?? 0);
        $imageIn = (int) ($usage['input_tokens_details']['image_tokens'] ?? 0);
        $out = (int) ($usage['output_tokens'] ?? 0);

        return new ImageResult($images, $textIn + $imageIn, $out, $model->cost($textIn, $imageIn, $out), (string) ($response->json('model') ?? $model->model));
    }

    /**
     * The pixel size for a shape: the short edge at the size's base (1024 or
     * 2048), the long edge from the ratio, both in multiples of 16, and the
     * long edge capped so wide shapes don't run away.
     */
    public static function size(string $ratio, string $size = ImageSize::DEFAULT): string
    {
        [$short, $longest] = self::EDGES[$size] ?? self::EDGES[ImageSize::DEFAULT];
        $value = max(1 / 3, min(3, AspectRatio::value($ratio) ?: 1.0));
        $round = fn (float $px) => (int) (round($px / 16) * 16);

        if ($value >= 1) {
            $width = min($longest, $round($short * $value));
            $height = $value > 1 ? $round($width / $value) : $width;
        } else {
            $height = min($longest, $round($short / $value));
            $width = $round($height * $value);
        }

        return "{$width}x{$height}";
    }

    private static function promptWithNotes(ImageRequest $request): string
    {
        $notes = collect($request->references)->map(fn ($r, $i) => 'Reference image '.($i + 1).($r->note ? ": {$r->note}" : ''))->implode("\n");

        return $request->prompt."\n\n".$notes;
    }

    private static function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    private function error(Response $response, Connection $connection): ImageException
    {
        $status = $response->status();
        $code = (string) ($response->json('error.code') ?? '');
        $message = (string) ($response->json('error.message') ?? '');

        if ($status === 401 || $status === 403) {
            return new ImageException("OpenAI refused the API key on the \"{$connection->name}\" connection. Check it under Connections.");
        }

        if ($code === 'insufficient_quota' || str_contains($message, 'quota')) {
            return new ImageException('OpenAI says the account is out of credit. Top it up at platform.openai.com.');
        }

        if ($status === 429) {
            return new ImageException('OpenAI is limiting requests right now. The run can be tried again shortly.', retryable: true);
        }

        if ($code === 'moderation_blocked' || $code === 'content_policy_violation' || str_contains($code, 'safety')) {
            return ImageException::blocked('OpenAI wouldn\'t make this image.'.($message ? ' '.$message : ''));
        }

        if ($status >= 500) {
            return new ImageException('OpenAI returned an error ('.($code ?: "HTTP {$status}").'). The run can be tried again shortly.', retryable: true);
        }

        return new ImageException('OpenAI returned an error ('.($code ?: "HTTP {$status}").').'.($message ? ' '.$message : ''));
    }

    private function http(Connection $connection, int $timeout): PendingRequest
    {
        return Http::withToken((string) $connection->secret('api_key'))->acceptJson()->timeout(max(10, $timeout));
    }
}

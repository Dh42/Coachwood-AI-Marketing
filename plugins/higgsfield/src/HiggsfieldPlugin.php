<?php

namespace Coachwood\Plugins\Higgsfield;

use App\Images\GeneratedImage;
use App\Images\ImageException;
use App\Images\ImageRequest;
use App\Images\ImageResult;
use App\Images\ReferenceImage;
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
use App\Settings\SettingScope;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Higgsfield's API: several makers' image models behind one key and one
 * prepaid balance. Every call is queued on Higgsfield's side, so a call is
 * submitted, then its status polled until the image is ready. Reference
 * images go up through Higgsfield's upload URLs first, since the models
 * take links, not bytes.
 */
class HiggsfieldPlugin extends IntegrationPlugin implements ImageProvider
{
    private const BASE = 'https://api.higgsfield.ai';

    /** How long Higgsfield keeps a request's files after it finishes: the least it allows, since the app downloads them at once. */
    private const KEEP_SECONDS = 3600;

    /**
     * What each known model takes, by its endpoint. A row added on the
     * Models page with another endpoint gets the common shape: quality,
     * resolution and image_urls.
     *
     * quality: the field the quality goes in, or null; resolution: whether
     * it takes "1k"/"2k"; references: image_urls (a list) or image_url (one);
     * edit: a separate endpoint for requests with reference images;
     * max_prompt: the longest prompt it takes; enhance: it rewrites prompts
     * unless told not to (the higgsfield.prompts setting).
     */
    private const MODELS = [
        'ideogram/v4.0' => ['quality' => 'rendering_speed', 'resolution' => false, 'references' => 'image_url', 'max_prompt' => 2048],
        'alibaba/qwen-image-3/text-to-image' => ['quality' => null, 'edit' => 'alibaba/qwen-image-3/edit', 'enhance' => true],
        'xai/grok-imagine-image-2.0' => [],
        'marketing-studio/image' => ['max_prompt' => 5000],
        'marketing-studio/image/flare' => ['max_prompt' => 5000],
    ];

    private const SHAPE = ['quality' => 'quality', 'resolution' => true, 'references' => 'image_urls', 'edit' => null, 'max_prompt' => null, 'enhance' => false];

    public function key(): string
    {
        return 'higgsfield';
    }

    public function name(): string
    {
        return 'Higgsfield';
    }

    public function description(): string
    {
        return 'Ideogram, Qwen, Grok and Higgsfield\'s Marketing Studio through one API key and a prepaid balance.';
    }

    public function connectionMethod(): ConnectionMethod
    {
        return ConnectionMethod::Fields;
    }

    public function connectionFields(): array
    {
        return [
            new ConnectionField('key_id', 'API key ID',
                help: 'From console.higgsfield.ai → API keys: the key\'s ID.',
                placeholder: '00000000-0000-…'),
            new ConnectionField('key_secret', 'API key secret',
                help: 'The secret shown once when the key was made. Stored encrypted and never shown again.',
                secret: true),
        ];
    }

    public function settings(): array
    {
        return [
            new SettingDefinition(
                key: 'higgsfield.quality',
                label: 'Higgsfield image quality',
                description: 'How carefully Higgsfield\'s models draw when a request doesn\'t say. Each model has its own steps (Ideogram: turbo, default, quality); this picks the low, middle or top one. Higher costs more: Marketing Studio at 1K is about 2 cents low, 6 cents medium, 22 cents high.',
                control: SettingControl::Select,
                default: 'medium',
                scopes: [SettingScope::Platform, SettingScope::Brand],
                rules: ['value' => ['required', 'in:low,medium,high']],
                section: 'Higgsfield',
                options: ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'],
            ),
            new SettingDefinition(
                key: 'higgsfield.prompts',
                label: 'Prompts to Higgsfield\'s models',
                description: 'Some models (Qwen Image 3) rewrite the prompt before drawing unless told not to. The planner already writes the prompt with the brand\'s rules, so it\'s sent as written unless this says otherwise.',
                control: SettingControl::Select,
                default: 'as_written',
                scopes: [SettingScope::Platform, SettingScope::Brand],
                rules: ['value' => ['required', 'in:as_written,rewrite']],
                section: 'Higgsfield',
                options: ['as_written' => 'Send the prompt as written', 'rewrite' => 'Let the model rewrite it first'],
                advanced: true,
            ),
        ];
    }

    public function accountLabel(Connection $connection): string
    {
        return 'the Higgsfield API';
    }

    public function healthCheck(Connection $connection): HealthResult
    {
        try {
            $response = $this->http($connection, 20)->get(self::BASE.'/models');
        } catch (ConnectionException) {
            return HealthResult::failed('Couldn\'t reach Higgsfield.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return HealthResult::failed('Higgsfield refused the API key.');
        }

        if ($response->failed()) {
            return HealthResult::failed("Higgsfield returned an error ({$response->status()}).");
        }

        return HealthResult::ok('Connected to the Higgsfield API');
    }

    /**
     * Prices from Higgsfield's estimate endpoint for this account, October
     * 2026. The actual cost of each call is asked of the same endpoint
     * before it's sent, since reference images change it.
     */
    public function imageModels(): array
    {
        $none = ['transparent' => false, 'input_price' => 0, 'image_input_price' => 0, 'output_price' => 0];

        return [
            [...$none, 'model' => 'ideogram/v4.0', 'label' => 'Ideogram 4.0 (Higgsfield)',
                'aspect_ratios' => ['1:1', '4:5', '5:4', '9:16', '16:9', '2:3', '3:2', '4:3', '3:4', '2:1', '1:2'],
                'sizes' => ['1K', '2K'], 'qualities' => ['turbo', 'default', 'quality'], 'max_references' => 1, 'edits' => true, 'renders_text' => true,
                'prices' => ['turbo 1K' => 0.03, 'turbo 2K' => 0.03, 'default 1K' => 0.06, 'default 2K' => 0.06, 'quality 1K' => 0.10, 'quality 2K' => 0.10],
                'notes' => 'Best at words in the picture. One reference image, which it remixes. Always about 2,000 px, whatever size is asked. Prompts up to 2,048 characters.', 'enabled' => true, 'position' => 10],
            [...$none, 'model' => 'alibaba/qwen-image-3/text-to-image', 'label' => 'Qwen Image 3 (Higgsfield)',
                'aspect_ratios' => ['1:1', '9:16', '16:9', '2:3', '3:2', '4:3', '3:4', '21:9'],
                'sizes' => ['1K', '2K'], 'qualities' => [], 'max_references' => 3, 'edits' => true, 'renders_text' => true,
                'prices' => ['1K' => 0.04, '2K' => 0.075],
                'notes' => 'Good text and edits from up to three reference images, at a low price. No 4:5; 3:4 is made and cropped.', 'enabled' => true, 'position' => 20],
            [...$none, 'model' => 'xai/grok-imagine-image-2.0', 'label' => 'Grok Imagine 2.0 (Higgsfield)',
                'aspect_ratios' => ['1:1', '1:2', '2:1', '3:2', '2:3', '4:3', '3:4', '16:9', '9:16'],
                'sizes' => ['1K', '2K'], 'qualities' => ['low', 'medium'], 'max_references' => 10, 'edits' => true, 'renders_text' => false,
                'prices' => ['low 1K' => 0.04, 'low 2K' => 0.06, 'medium 1K' => 0.06, 'medium 2K' => 0.08],
                'notes' => 'xAI\'s model: up to ten reference images. One image per call.', 'enabled' => true, 'position' => 30],
            [...$none, 'model' => 'marketing-studio/image', 'label' => 'Marketing Studio Image (Higgsfield)',
                'aspect_ratios' => ['1:1', '3:2', '2:3', '4:3', '3:4', '16:9', '9:16', '21:9'],
                'sizes' => ['1K', '2K', '4K'], 'qualities' => ['low', 'medium', 'high'], 'max_references' => 16, 'edits' => true, 'renders_text' => true,
                'prices' => ['low 1K' => 0.017, 'low 2K' => 0.023, 'low 4K' => 0.031, 'medium 1K' => 0.063, 'medium 2K' => 0.118, 'medium 4K' => 0.189, 'high 1K' => 0.221, 'high 2K' => 0.439, 'high 4K' => 0.722],
                'notes' => 'Higgsfield\'s campaign image model: up to 16 reference images, strong text. Reference images add to the price (two add about 2.5 cents at medium 1K).', 'enabled' => true, 'position' => 40],
            [...$none, 'model' => 'marketing-studio/image/flare', 'label' => 'Marketing Studio Image 2.5 Flare (Higgsfield)',
                'aspect_ratios' => ['1:1', '3:2', '2:3', '4:3', '3:4', '16:9', '9:16', '21:9'],
                'sizes' => ['1K', '2K', '4K'], 'qualities' => ['low', 'medium', 'high', 'xhigh', 'max'], 'max_references' => 16, 'edits' => true, 'renders_text' => true,
                'prices' => ['low 1K' => 0.017, 'low 2K' => 0.023, 'low 4K' => 0.031, 'medium 1K' => 0.063, 'medium 2K' => 0.118, 'medium 4K' => 0.189, 'high 1K' => 0.221, 'high 2K' => 0.439, 'high 4K' => 0.722],
                'input_price' => 5, 'image_input_price' => 8, 'output_price' => 30,
                'notes' => 'On GPT Image 2.5 Flare. Higgsfield prices it by tokens and settles the cost after the call, so the per-image prices are copied from Marketing Studio Image and the xhigh and max qualities aren\'t priced. Check before switching on.', 'enabled' => false, 'position' => 50],
        ];
    }

    public function defaultQuality(ImageModel $model, ?Brand $brand = null): ?string
    {
        if (! $model->hasQualities()) {
            return null;
        }

        $wanted = (string) app(Settings::class)->get('higgsfield.quality', $brand?->id);
        if ($model->takesQuality($wanted)) {
            return $wanted;
        }

        $steps = $model->qualities;

        return match ($wanted) {
            'low' => $steps[0],
            'high' => $steps[count($steps) - 1],
            default => $steps[intdiv(count($steps) - 1, 2)],
        };
    }

    public function generateImages(Connection $connection, ImageModel $model, ImageRequest $request): ImageResult
    {
        $spec = [...self::SHAPE, ...(self::MODELS[$model->model] ?? [])];
        $timeout = (int) app(Settings::class)->get('images.timeout_seconds');
        $prompt = $request->references === [] ? $request->prompt : self::promptWithNotes($request);

        if ($spec['max_prompt'] && mb_strlen($prompt) > $spec['max_prompt']) {
            throw new ImageException("{$model->label} takes prompts up to ".number_format($spec['max_prompt']).' characters; this one has '.number_format(mb_strlen($prompt)).'. Choose another model for this request.');
        }

        try {
            $urls = array_map(fn (ReferenceImage $reference) => $this->upload($connection, $reference), $request->references);
            $endpoint = $urls !== [] && $spec['edit'] ? $spec['edit'] : $model->model;
            $body = $this->body($spec, $prompt, $request, $request->quality ?? $this->defaultQuality($model), $urls);
            $each = $this->estimate($connection, $endpoint, $body) ?? $model->price($request->size, $request->quality ?? $this->defaultQuality($model)) ?? 0.0;

            $pending = [];
            for ($i = 0; $i < max(1, $request->count); $i++) {
                $pending[] = $this->submit($connection, $endpoint, $body);
            }

            [$done, $problems] = $this->wait($connection, $pending, $timeout);
            $images = array_map(fn (string $url) => $this->download($url, $timeout), $done);
        } catch (ConnectionException) {
            throw new ImageException('Couldn\'t reach Higgsfield. The run can be tried again shortly.', retryable: true);
        }

        if ($images === []) {
            throw $problems[0] ?? new ImageException('Higgsfield sent no image and no reason.');
        }

        return new ImageResult($images, 0, 0, round($each * count($images), 4), $endpoint);
    }

    /**
     * The request body for a model, in its own field names.
     *
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $urls
     * @return array<string, mixed>
     */
    private function body(array $spec, string $prompt, ImageRequest $request, ?string $quality, array $urls): array
    {
        $body = ['prompt' => $prompt, 'aspect_ratio' => $request->aspectRatio];

        if ($spec['quality'] && $quality) {
            $body[$spec['quality']] = $spec['quality'] === 'rendering_speed' ? strtoupper($quality) : $quality;
        }
        if ($spec['resolution']) {
            $body['resolution'] = strtolower($request->size);
        }
        if ($spec['enhance']) {
            $rewrite = app(Settings::class)->get('higgsfield.prompts') === 'rewrite';
            $body['prompt_extend'] = $rewrite;
            $body['enable_thinking'] = $rewrite;
        }
        if ($urls !== []) {
            $body[$spec['references']] = $spec['references'] === 'image_url' ? $urls[0] : $urls;
        }

        return $body;
    }

    /**
     * Puts a reference image where Higgsfield can read it, and returns its link.
     */
    private function upload(Connection $connection, ReferenceImage $reference): string
    {
        $mime = in_array($reference->mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) ? $reference->mime : 'image/png';
        $response = $this->http($connection, 30)->post(self::BASE.'/files/generate-upload-url', ['content_type' => $mime]);

        if ($response->failed() || blank($response->json('upload_url')) || blank($response->json('public_url'))) {
            throw $response->failed() ? $this->error($response, $connection) : new ImageException('Higgsfield didn\'t give a place to upload the reference image.', retryable: true);
        }

        $put = Http::withHeaders((array) ($response->json('upload_headers') ?? ['Content-Type' => $mime]))
            ->withBody($reference->data, $mime)->timeout(60)->put((string) $response->json('upload_url'));

        if ($put->failed()) {
            throw new ImageException("Uploading a reference image to Higgsfield failed ({$put->status()}). The run can be tried again shortly.", retryable: true);
        }

        return (string) $response->json('public_url');
    }

    /**
     * What Higgsfield says the call will cost this account, in US dollars,
     * or null when it prices the model by tokens or can't say.
     *
     * @param  array<string, mixed>  $body
     */
    private function estimate(Connection $connection, string $endpoint, array $body): ?float
    {
        $response = $this->http($connection, 20)->post(self::BASE.'/estimate/'.$endpoint, $body);

        if ($response->failed() || $response->json('type') !== 'estimate' || ! is_numeric($response->json('usd'))) {
            return null;
        }

        return (float) $response->json('usd');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status_url: string, cancel_url: string}
     */
    private function submit(Connection $connection, string $endpoint, array $body): array
    {
        $response = $this->http($connection, 30)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid(), 'Hf-Expires-After' => (string) self::KEEP_SECONDS])
            ->post(self::BASE.'/'.$endpoint, $body);

        if ($response->failed()) {
            throw $this->error($response, $connection);
        }

        $id = (string) $response->json('request_id');

        return [
            'status_url' => (string) ($response->json('status_url') ?: self::BASE."/requests/{$id}/status"),
            'cancel_url' => (string) ($response->json('cancel_url') ?: self::BASE."/requests/{$id}/cancel"),
        ];
    }

    /**
     * Polls each call until it's finished, from two seconds apart up to ten,
     * for as long as an image may take. What hasn't started by then is
     * cancelled (and not charged).
     *
     * @param  list<array{status_url: string, cancel_url: string}>  $pending
     * @return array{0: list<string>, 1: list<ImageException>} the image links, and why the others failed.
     */
    private function wait(Connection $connection, array $pending, int $timeout): array
    {
        $done = [];
        $problems = [];
        $delay = 2.0;
        $waited = 0.0;

        while ($pending !== []) {
            Sleep::for((int) round($delay * 1000))->milliseconds();
            $waited += $delay;
            $delay = min($delay * 1.5, 10.0);

            foreach ($pending as $i => $call) {
                $response = $this->http($connection, 30)->get($call['status_url']);
                if ($response->status() === 401) {
                    throw $this->error($response, $connection);
                }
                $status = $response->status() === 404 ? 'lost' : (string) $response->json('status');
                // A failed status check (an outage, a timeout) is asked again next round.
                if (in_array($status, ['queued', 'in_progress', ''], true) || ($response->failed() && $status !== 'lost')) {
                    continue;
                }

                unset($pending[$i]);
                if ($status === 'completed') {
                    foreach ((array) $response->json('images') as $image) {
                        if (filled($image['url'] ?? null)) {
                            $done[] = (string) $image['url'];
                        }
                    }
                } elseif ($status === 'nsfw') {
                    $problems[] = ImageException::blocked('Higgsfield\'s moderation refused this image. Change the prompt or the reference images.');
                } elseif ($status === 'lost') {
                    $problems[] = new ImageException('Higgsfield no longer knows the call. The run can be tried again.', retryable: true);
                } elseif ($status === 'canceled') {
                    $problems[] = new ImageException('The call was cancelled on Higgsfield before it started.', retryable: true);
                } else {
                    $problems[] = $this->failure((string) $response->json('error'));
                }
            }

            if ($pending !== [] && $waited >= $timeout) {
                foreach ($pending as $call) {
                    $this->http($connection, 10)->post($call['cancel_url']);
                }
                $problems[] = new ImageException("Higgsfield hadn't finished the image after {$timeout} seconds. What hadn't started was cancelled; an image already being drawn may still be charged.");
                break;
            }
        }

        return [$done, $problems];
    }

    private function download(string $url, int $timeout): GeneratedImage
    {
        $response = Http::timeout(max(30, $timeout))->get($url);

        if ($response->failed() || $response->body() === '') {
            throw new ImageException("Downloading the finished image from Higgsfield failed ({$response->status()}).", retryable: true);
        }

        return GeneratedImage::fromBytes($response->body());
    }

    private function failure(string $error): ImageException
    {
        if (self::namesSomeone($error)) {
            return ImageException::blocked('Higgsfield refused the prompt: it names a brand, a trademark or a real person. The planner keeps brand names out of image prompts; check the prompt and the references.');
        }

        return new ImageException('Higgsfield couldn\'t make the image'.($error !== '' ? ": {$error}" : '').'. Failed calls aren\'t charged; the run can be tried again.', retryable: true);
    }

    private function error(Response $response, Connection $connection): ImageException
    {
        $status = $response->status();
        $detail = self::detail($response);

        if ($status === 401 || ($status === 403 && str_contains(strtolower($detail), 'key'))) {
            return new ImageException("Higgsfield refused the API key on the \"{$connection->name}\" connection. Check it under Connections.");
        }

        if ($status === 403) {
            return new ImageException('Higgsfield says the balance is too low. Top it up at console.higgsfield.ai.');
        }

        if ($status === 404) {
            return new ImageException('Higgsfield doesn\'t have this model, or not for this account. Check the model ID on the Models page.');
        }

        if ($status === 429) {
            return new ImageException('Higgsfield\'s queue for the account is full. The run can be tried again shortly.', retryable: true);
        }

        if ($status === 423 || $status >= 500) {
            return new ImageException("Higgsfield can't take the call right now ({$status}). The run can be tried again shortly.", retryable: true);
        }

        if (self::namesSomeone($detail)) {
            return $this->failure($detail);
        }

        return new ImageException("Higgsfield returned an error ({$status}).".($detail !== '' ? " {$detail}" : ''));
    }

    private static function detail(Response $response): string
    {
        $detail = $response->json('detail');

        if (is_array($detail)) {
            return collect($detail)->map(fn ($d) => is_array($d) ? ($d['msg'] ?? '') : (string) $d)->filter()->implode(' ');
        }

        return mb_substr(trim((string) $detail), 0, 500);
    }

    private static function namesSomeone(string $text): bool
    {
        return str_contains(strtolower($text), 'ip_detected');
    }

    private static function promptWithNotes(ImageRequest $request): string
    {
        $notes = collect($request->references)->map(fn ($r, $i) => 'Reference image '.($i + 1).($r->note ? ": {$r->note}" : ''))->implode("\n");

        return $request->prompt."\n\n".$notes;
    }

    private function http(Connection $connection, int $timeout): PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'Key '.$connection->config('key_id').':'.$connection->secret('key_secret')])
            ->acceptJson()->timeout(max(10, $timeout));
    }
}

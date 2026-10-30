<?php

namespace Coachwood\Plugins\Anthropic;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ToolSpec;
use App\Models\Connection;
use App\Models\LlmModel;
use App\Plugins\ConnectionField;
use App\Plugins\ConnectionMethod;
use App\Plugins\Contracts\LanguageProvider;
use App\Plugins\HealthResult;
use App\Plugins\IntegrationPlugin;
use Illuminate\Support\Facades\Http;

/**
 * Claude through Anthropic's official PHP SDK (beta messages, for the
 * server-side refusal fallback). Adaptive thinking with the configured
 * effort; the system prompt and conversation are cached between the turns
 * of a tool loop. The key lives on an Anthropic connection, shared by
 * every brand or one per brand.
 */
class AnthropicPlugin extends IntegrationPlugin implements LanguageProvider
{
    private const REFUSAL_FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private const API_VERSION = '2023-06-01';

    /**
     * @param  array<string, mixed>  $requestOptions  Passed to the SDK (tests use it to swap the HTTP client).
     */
    public function __construct(private array $requestOptions = []) {}

    public function key(): string
    {
        return 'anthropic';
    }

    public function name(): string
    {
        return 'Anthropic';
    }

    public function description(): string
    {
        return 'The Claude models that read the brief, plan the run, write the prompts and copy, and review every output.';
    }

    public function connectionMethod(): ConnectionMethod
    {
        return ConnectionMethod::Fields;
    }

    public function connectionFields(): array
    {
        return [
            new ConnectionField('api_key', 'API key',
                help: 'From console.anthropic.com → API keys. Stored encrypted and never shown again.',
                secret: true,
                placeholder: 'sk-ant-…'),
        ];
    }

    public function accountLabel(Connection $connection): string
    {
        return 'the Anthropic API';
    }

    public function healthCheck(Connection $connection): HealthResult
    {
        $response = Http::withHeaders(['x-api-key' => (string) $connection->secret('api_key'), 'anthropic-version' => self::API_VERSION])
            ->acceptJson()->timeout(20)->get('https://api.anthropic.com/v1/models', ['limit' => 1]);

        if ($response->status() === 401 || $response->status() === 403) {
            return HealthResult::failed('Anthropic refused the API key.');
        }

        if ($response->failed()) {
            return HealthResult::failed("Anthropic returned an error ({$response->status()}).");
        }

        return HealthResult::ok('Connected to the Anthropic API');
    }

    public function complete(LlmRequest $request, LlmModel $model, Connection $connection): LlmResponse
    {
        $params = [
            'model' => $request->model,
            'maxTokens' => $request->maxTokens,
            // The prompt is the stable prefix; the conversation grows each round of a tool loop.
            'system' => [['type' => 'text', 'text' => $request->system, 'cacheControl' => ['type' => 'ephemeral']]],
            'cacheControl' => ['type' => 'ephemeral'],
            'messages' => array_map($this->message(...), $request->messages),
        ];

        if ($request->tools) {
            $params['tools'] = array_map(fn (ToolSpec $t) => [
                'name' => $t->name,
                'description' => $t->description,
                'inputSchema' => $this->schema($t->schema),
            ], $request->tools);
        }

        if ($model->thinking === 'adaptive') {
            $params['thinking'] = ['type' => 'adaptive'];
            $params['outputConfig'] = ['effort' => $request->effort];
        }

        if ($model->refusal_fallback) {
            $params['fallbacks'] = 'default';
            $params['betas'] = [self::REFUSAL_FALLBACK_BETA];
        }

        try {
            $message = $this->client($connection)->beta->messages->create(...$params);
        } catch (AuthenticationException|PermissionDeniedException) {
            throw new LlmException("Anthropic refused the API key on the \"{$connection->name}\" connection. Check it under Connections.");
        } catch (RateLimitException) {
            throw new LlmException('Anthropic is limiting requests right now. The run can be tried again shortly.', retryable: true);
        } catch (APIStatusException $e) {
            $type = $e->type?->value ?? "HTTP {$e->status}";

            throw new LlmException("Anthropic returned an error ({$type}).", retryable: $type === 'overloaded_error' || ($e->status ?? 0) >= 500);
        } catch (APIConnectionException) {
            throw new LlmException('Couldn\'t reach Anthropic. The run can be tried again shortly.', retryable: true);
        }

        $text = '';
        $toolCalls = [];

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            } elseif ($block->type === 'tool_use') {
                $toolCalls[] = ['id' => $block->id, 'name' => $block->name, 'input' => $block->input];
            }
        }

        return new LlmResponse(
            text: trim($text),
            toolCalls: $toolCalls,
            stopReason: match ($message->stopReason) {
                'tool_use' => LlmResponse::TOOL_USE,
                'max_tokens' => LlmResponse::MAX_TOKENS,
                'refusal' => LlmResponse::REFUSAL,
                default => LlmResponse::END,
            },
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            cacheWriteTokens: (int) $message->usage->cacheCreationInputTokens,
            cacheReadTokens: (int) $message->usage->cacheReadInputTokens,
            servedBy: $message->model,
            // Sent back unchanged: thinking and fallback blocks must round-trip within a tool loop.
            assistantMessage: ['role' => 'assistant', 'raw' => $message->content, 'content' => []],
        );
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function message(array $message): array
    {
        if ($message['role'] === 'assistant' && isset($message['raw'])) {
            return ['role' => 'assistant', 'content' => $message['raw']];
        }

        // Plain text is a single text block.
        if (is_string($message['content'])) {
            $message['content'] = [['type' => 'text', 'text' => $message['content']]];
        }

        return [
            'role' => $message['role'],
            'content' => array_map(fn (array $block) => match ($block['type']) {
                'text' => ['type' => 'text', 'text' => $block['text']],
                'image' => ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $block['media_type'], 'data' => $block['data']]],
                'tool_use' => ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => $block['input'] ?: new \stdClass],
                'tool_result' => ['type' => 'tool_result', 'toolUseID' => $block['tool_use_id'], 'content' => $block['content'], 'isError' => (bool) ($block['is_error'] ?? false)],
            }, $message['content']),
        ];
    }

    /**
     * An empty properties list must be a JSON object, not an array.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function schema(array $schema): array
    {
        if (empty($schema['properties'])) {
            $schema['properties'] = new \stdClass;
        }

        return $schema;
    }

    private function client(Connection $connection): Client
    {
        $key = $connection->secret('api_key');

        if (blank($key)) {
            throw new LlmException("The Anthropic connection \"{$connection->name}\" has no API key. Add one under Connections.");
        }

        return new Client(apiKey: $key, requestOptions: $this->requestOptions ?: null);
    }
}

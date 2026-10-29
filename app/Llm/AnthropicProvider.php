<?php

namespace App\Llm;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Models\LlmModel;
use App\Settings\Settings;

/**
 * Claude through Anthropic's official PHP SDK (beta messages, for the
 * server-side refusal fallback). Adaptive thinking with the configured
 * effort; the system prompt and conversation are cached between the turns
 * of a tool loop.
 */
class AnthropicProvider implements LlmProvider
{
    private const REFUSAL_FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * @param  array<string, mixed>  $requestOptions  Passed to the SDK (tests use it to swap the HTTP client).
     */
    public function __construct(private array $requestOptions = []) {}

    public function complete(LlmRequest $request, LlmModel $model): LlmResponse
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
            $message = $this->client()->beta->messages->create(...$params);
        } catch (AuthenticationException|PermissionDeniedException) {
            throw new LlmException('Anthropic refused the API key. Check it under Settings → AI models.');
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

    private function client(): Client
    {
        $key = app(Settings::class)->get('llm.anthropic_api_key') ?: config('services.anthropic.key');

        if (blank($key)) {
            throw new LlmException('No Anthropic API key is set. Add one under Settings → AI models.');
        }

        return new Client(apiKey: $key, requestOptions: $this->requestOptions ?: null);
    }
}

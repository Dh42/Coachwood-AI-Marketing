<?php

use App\Llm\AnthropicProvider;
use App\Llm\LlmException;
use App\Llm\LlmManager;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ToolSpec;
use App\Settings\Settings;
use App\Settings\SettingScope;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Checks the exact requests the official SDK sends, and how responses are
 * read, by giving the SDK a stand-in HTTP client with canned replies.
 */
beforeEach(function () {
    $admin = signInAs();
    app(Settings::class)->set('llm.anthropic_api_key', 'sk-ant-test', SettingScope::Platform, 0, $admin);
    $this->sent = new ArrayObject;
});

function anthropic(array $replies): LlmManager
{
    $stack = HandlerStack::create(new MockHandler($replies));
    $history = test()->sent;
    $stack->push(Middleware::history($history));
    $manager = new LlmManager;
    $manager->register('anthropic', new AnthropicProvider(['transporter' => new Client(['handler' => $stack]), 'maxRetries' => 0]));

    return $manager;
}

function message(array $content, string $stop = 'end_turn', string $model = 'claude-opus-5'): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => $model,
        'content' => $content, 'stop_reason' => $stop, 'stop_sequence' => null,
        'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'cache_creation_input_tokens' => 1000, 'cache_read_input_tokens' => 2000],
    ]));
}

function llmRequest(?array $messages = null, array $tools = []): LlmRequest
{
    return new LlmRequest('claude-opus-5', 'You are the router.', $messages ?? [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]]], $tools, effort: 'medium');
}

it('sends a well-formed request: cached prompt, tools, adaptive thinking, effort and the refusal fallback', function () {
    $llm = anthropic([message([['type' => 'text', 'text' => 'Hello!']])]);

    $response = $llm->complete(llmRequest(tools: [new ToolSpec('read_context', 'Read the conversation.', ['type' => 'object', 'properties' => [], 'required' => []])]));

    expect($response->text)->toBe('Hello!')->and($response->stopReason)->toBe(LlmResponse::END);

    $http = $this->sent[0]['request'];
    $body = json_decode((string) $http->getBody(), true);

    expect((string) $http->getUri())->toContain('/v1/messages')
        ->and($http->getHeaderLine('x-api-key'))->toBe('sk-ant-test')
        ->and($http->getHeaderLine('anthropic-beta'))->toContain('server-side-fallback-2026-07-01')
        ->and($body['model'])->toBe('claude-opus-5')
        ->and($body['max_tokens'])->toBe(16000)
        ->and($body['system'][0])->toBe(['type' => 'text', 'text' => 'You are the router.', 'cache_control' => ['type' => 'ephemeral']])
        ->and($body['thinking'])->toBe(['type' => 'adaptive'])
        ->and($body['output_config'])->toBe(['effort' => 'medium'])
        ->and($body['fallbacks'])->toBe('default')
        ->and($body['tools'][0]['name'])->toBe('read_context')
        ->and((string) $http->getBody())->toContain('"properties":{}');
});

it('reads tool calls, and sends the results back after the assistant\'s own content', function () {
    $llm = anthropic([
        message([['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'shopify__get_order', 'input' => ['order_number' => '1234']]], 'tool_use'),
        message([['type' => 'text', 'text' => 'It shipped.']]),
    ]);

    $first = $llm->complete(llmRequest());
    expect($first->stopReason)->toBe(LlmResponse::TOOL_USE)
        ->and($first->toolCalls)->toBe([['id' => 'tu_1', 'name' => 'shopify__get_order', 'input' => ['order_number' => '1234']]]);

    $llm->complete(llmRequest([
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Where is 1234?']]],
        $first->assistantMessage,
        ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => '{"status":"shipped"}', 'is_error' => false]]],
    ]));

    $body = json_decode((string) $this->sent[1]['request']->getBody(), true);
    expect($body['messages'][1]['content'][0]['type'])->toBe('thinking')
        ->and($body['messages'][1]['content'][0]['signature'])->toBe('sig')
        ->and($body['messages'][2]['content'][0])->toMatchArray(['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => '{"status":"shipped"}']);
});

it('counts cached tokens and prices them at cache rates', function () {
    $response = anthropic([message([['type' => 'text', 'text' => 'ok']])])->complete(llmRequest());

    // Opus 5: 100 in at $5, 50 out at $25, 1,000 cache writes at $6.25, 2,000 cache reads at $0.50 (per million).
    expect($response->cacheWriteTokens)->toBe(1000)->and($response->cacheReadTokens)->toBe(2000)
        ->and(round($response->cost, 6))->toBe(0.009);
});

it('skips thinking settings and the fallback on models without them', function () {
    anthropic([message([['type' => 'text', 'text' => 'ok']], model: 'claude-haiku-4-5')])
        ->complete(new LlmRequest('claude-haiku-4-5', 'x', [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]]]));

    $body = json_decode((string) $this->sent[0]['request']->getBody(), true);
    expect($body)->not->toHaveKey('thinking')->not->toHaveKey('output_config')->not->toHaveKey('fallbacks');
});

it('reports a refusal', function () {
    expect(anthropic([message([], 'refusal')])->complete(llmRequest())->stopReason)->toBe(LlmResponse::REFUSAL);
});

it('turns API errors into safe messages that never include the key', function (int $status, string $type, string $message, bool $retryable) {
    $llm = anthropic([new Response($status, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => 'details']]))]);

    try {
        $llm->complete(llmRequest());
        $this->fail('Expected an error');
    } catch (LlmException $e) {
        expect($e->getMessage())->toContain($message)->not->toContain('sk-ant-test')
            ->and($e->retryable)->toBe($retryable);
    }
})->with([
    'bad key' => [401, 'authentication_error', 'refused the API key', false],
    'rate limited' => [429, 'rate_limit_error', 'limiting requests', true],
    'overloaded' => [529, 'overloaded_error', 'overloaded_error', true],
    'bad request' => [400, 'invalid_request_error', 'invalid_request_error', false],
]);

it('explains when no API key is set', function () {
    app(Settings::class)->reset('llm.anthropic_api_key', SettingScope::Platform, 0, auth()->user());
    config(['services.anthropic.key' => null]);

    anthropic([])->complete(llmRequest());
})->throws(LlmException::class, 'No Anthropic API key is set');

it('sends images as base64 image blocks', function () {
    $llm = anthropic([message([['type' => 'text', 'text' => 'I can see the crack.']])]);

    $llm->complete(llmRequest([['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => 'Look at this.'],
        ['type' => 'image', 'media_type' => 'image/jpeg', 'data' => 'aGVsbG8='],
    ]]]));

    $body = json_decode((string) $this->sent[0]['request']->getBody(), true);
    expect($body['messages'][0]['content'][1])->toBe(['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => 'aGVsbG8=']]);
});

it('sends a message given as plain text as one text block', function () {
    $llm = anthropic([message([['type' => 'text', 'text' => 'Thanks!']])]);

    expect($llm->complete(llmRequest([['role' => 'user', 'content' => 'A five-star review.']]))->text)->toBe('Thanks!');

    $body = json_decode((string) $this->sent[0]['request']->getBody(), true);
    expect($body['messages'])->toBe([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'A five-star review.']]]]);
});

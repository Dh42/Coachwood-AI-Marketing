<?php

namespace Tests\Support;

use App\Llm\LlmException;
use App\Llm\LlmProvider;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Models\LlmModel;
use Closure;

/**
 * A scripted model. Each call takes the next reply from the script; a
 * reply is a closure given the request, a string (final text), or a list of
 * tool calls [name, input].
 */
class FakeLlm implements LlmProvider
{
    /** @var list<LlmRequest> */
    public array $requests = [];

    /** @param list<Closure|string|array|LlmException> $script */
    public function __construct(private array $script = []) {}

    public function push(Closure|string|array|LlmException ...$replies): self
    {
        array_push($this->script, ...$replies);

        return $this;
    }

    public function complete(LlmRequest $request, LlmModel $model): LlmResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->script) ?? throw new \RuntimeException('FakeLlm ran out of scripted replies.');

        if ($next instanceof LlmException) {
            throw $next;
        }

        if ($next instanceof Closure) {
            $next = $next($request);
        }

        if (is_string($next)) {
            return self::text($next);
        }

        return $next instanceof LlmResponse ? $next : self::tools($next);
    }

    public static function text(string $text, string $stop = LlmResponse::END): LlmResponse
    {
        return new LlmResponse($text, [], $stop, 1000, 200, 0, 0, 'fake', ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $text]]]);
    }

    /**
     * @param  list<array{0: string, 1: array}>  $calls  [tool name, input]
     */
    public static function tools(array $calls): LlmResponse
    {
        $calls = array_map(fn ($c, $i) => ['id' => "call_{$i}_".uniqid(), 'name' => $c[0], 'input' => $c[1]], $calls, array_keys($calls));

        return new LlmResponse('', $calls, LlmResponse::TOOL_USE, 1000, 100, 0, 0, 'fake', [
            'role' => 'assistant',
            'content' => array_map(fn ($c) => ['type' => 'tool_use', ...$c], $calls),
        ]);
    }
}

<?php

namespace App\Llm;

final readonly class LlmResponse
{
    public const END = 'end';

    public const TOOL_USE = 'tool_use';

    public const MAX_TOKENS = 'max_tokens';

    public const REFUSAL = 'refusal';

    /**
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $toolCalls
     * @param  array<string, mixed>  $assistantMessage  To append to the conversation as-is.
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public string $stopReason,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheWriteTokens,
        public int $cacheReadTokens,
        public string $servedBy,
        public array $assistantMessage,
        public float $cost = 0,
    ) {}

    public function withCost(float $cost): self
    {
        return new self($this->text, $this->toolCalls, $this->stopReason, $this->inputTokens, $this->outputTokens,
            $this->cacheWriteTokens, $this->cacheReadTokens, $this->servedBy, $this->assistantMessage, $cost);
    }
}

<?php

namespace App\Llm;

/**
 * One model call, provider-neutral.
 *
 * Messages are ['role' => 'user'|'assistant', 'content' => list of blocks],
 * where a block is one of:
 *   ['type' => 'text', 'text' => ...]
 *   ['type' => 'tool_use', 'id' => ..., 'name' => ..., 'input' => [...]]
 *   ['type' => 'tool_result', 'tool_use_id' => ..., 'content' => string, 'is_error' => bool]
 * An assistant message may also carry 'raw': the provider's own content,
 * which it sends back unchanged (Anthropic needs its thinking blocks back
 * mid tool loop). The brand says whose provider connection to use.
 */
final readonly class LlmRequest
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<ToolSpec>  $tools
     */
    public function __construct(
        public string $model,
        public string $system,
        public array $messages,
        public array $tools = [],
        public int $maxTokens = 16000,
        public string $effort = 'high',
        public ?int $brandId = null,
    ) {}
}

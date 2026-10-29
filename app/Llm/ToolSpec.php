<?php

namespace App\Llm;

/**
 * A tool the model may call, described the same way for every provider.
 */
final readonly class ToolSpec
{
    /**
     * @param  array<string, mixed>  $schema  JSON Schema for the input.
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $schema,
    ) {}
}

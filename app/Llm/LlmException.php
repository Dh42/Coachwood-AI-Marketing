<?php

namespace App\Llm;

use RuntimeException;

/**
 * A provider error, with a message safe to show and store (never the
 * request, so API keys can't leak into logs or traces).
 */
class LlmException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}

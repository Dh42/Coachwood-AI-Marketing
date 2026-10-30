<?php

namespace App\Images;

use RuntimeException;

/**
 * A provider or request problem, with a message safe to show and store
 * (never the request, so keys and prompts can't leak into logs).
 */
class ImageException extends RuntimeException
{
    /**
     * @param  bool  $retryable  Worth trying again as it is (rate limits, outages).
     * @param  bool  $blocked  The provider refused the content; the prompt or references must change.
     */
    public function __construct(string $message, public readonly bool $retryable = false, public readonly bool $blocked = false)
    {
        parent::__construct($message);
    }

    public static function blocked(string $message): self
    {
        return new self($message, retryable: false, blocked: true);
    }
}

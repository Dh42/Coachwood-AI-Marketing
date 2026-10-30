<?php

namespace App\Catalogue;

use RuntimeException;

/**
 * A background remover couldn't make a cut-out, with a message safe to show
 * and keep on the photo (never the key).
 */
class CutoutException extends RuntimeException
{
    /**
     * @param  bool  $retryable  Worth trying again as it is (rate limits, outages).
     */
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}

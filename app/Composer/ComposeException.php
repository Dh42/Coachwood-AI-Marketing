<?php

namespace App\Composer;

use RuntimeException;

/**
 * The composer couldn't make an ad: a message safe to show on the request.
 */
class ComposeException extends RuntimeException {}

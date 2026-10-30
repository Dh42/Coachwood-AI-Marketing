<?php

namespace App\Catalogue;

/**
 * What a background remover sends back: PNG bytes with a see-through
 * background, and what the call cost in US dollars.
 */
final readonly class Cutout
{
    public function __construct(public string $png, public float $cost = 0.0) {}
}

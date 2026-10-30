<?php

namespace App\Composer;

/**
 * The words and pictures that go on one finished ad.
 */
final readonly class AdContent
{
    /**
     * @param  string|null  $image  Path on the app's disk of the clean image.
     * @param  string|null  $logo  Path on the app's disk of the logo file.
     * @param  string|null  $product  Path on the app's disk of a transparent pack shot.
     * @param  list<array{family: string, role: string, path: ?string}>  $fonts
     * @param  array<string, string>  $colours  role => hex (primary, secondary, accent, neutral).
     */
    public function __construct(
        public string $headline,
        public string $subline = '',
        public string $cta = '',
        public string $legal = '',
        public string $price = '',
        public ?string $image = null,
        public ?string $logo = null,
        public ?string $product = null,
        public array $fonts = [],
        public array $colours = [],
        public string $language = 'en',
        public string $productNote = '',
    ) {}
}

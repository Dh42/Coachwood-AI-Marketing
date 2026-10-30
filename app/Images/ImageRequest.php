<?php

namespace App\Images;

/**
 * One ask of an image model, provider-neutral: the prompt, the shape, how
 * big, how careful, and any reference images.
 *
 * @see ImageGenerator for what checks the request against the model.
 */
final readonly class ImageRequest
{
    /**
     * @param  string  $aspectRatio  A label such as "4:5" (App\Platforms\AspectRatio).
     * @param  string  $size  One of ImageSize::OPTIONS.
     * @param  string|null  $quality  A provider quality name, or null for the provider's default.
     * @param  list<ReferenceImage>  $references
     * @param  int  $count  How many images to make from the same prompt.
     * @param  bool  $transparent  Ask for a transparent background where the model can.
     */
    public function __construct(
        public string $prompt,
        public string $aspectRatio = '1:1',
        public string $size = ImageSize::DEFAULT,
        public ?string $quality = null,
        public array $references = [],
        public int $count = 1,
        public bool $transparent = false,
    ) {}

    public function with(mixed ...$changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}

<?php

namespace App\Images;

/**
 * What came back from one request: the images, what it cost, and anything
 * the model said alongside.
 */
final readonly class ImageResult
{
    /**
     * @param  list<GeneratedImage>  $images
     * @param  float  $cost  US dollars, from the model's token prices and the provider's usage figures.
     * @param  string  $aspectRatio  The ratio the images were made at (the closest the model takes when the asked-for one isn't).
     * @param  string  $text  Anything the model said in words, such as why it changed the picture.
     */
    public function __construct(
        public array $images,
        public int $inputTokens,
        public int $outputTokens,
        public float $cost,
        public string $servedBy,
        public string $aspectRatio = '',
        public string $text = '',
    ) {}

    public function withAspectRatio(string $ratio): self
    {
        return new self($this->images, $this->inputTokens, $this->outputTokens, $this->cost, $this->servedBy, $ratio, $this->text);
    }
}

<?php

namespace App\Images;

/**
 * One image back from a provider, as bytes, with what's known about it.
 */
final readonly class GeneratedImage
{
    public function __construct(
        public string $data,
        public string $mime,
        public int $width,
        public int $height,
    ) {}

    public static function fromBytes(string $data, ?string $mime = null): self
    {
        $info = @getimagesizefromstring($data) ?: [0, 0, 'mime' => $mime ?? 'application/octet-stream'];

        return new self($data, $mime ?? $info['mime'], (int) $info[0], (int) $info[1]);
    }

    public function extension(): string
    {
        return match ($this->mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
    }
}

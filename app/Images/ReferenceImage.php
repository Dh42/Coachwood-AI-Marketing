<?php

namespace App\Images;

use Illuminate\Support\Facades\Storage;

/**
 * An image sent with the prompt: a pack shot, a logo for placement, a style
 * reference. The note tells the model what it is.
 */
final readonly class ReferenceImage
{
    public function __construct(
        public string $data,
        public string $mime,
        public string $note = '',
    ) {}

    public static function fromDisk(string $path, string $note = '', ?string $disk = null): self
    {
        $storage = Storage::disk($disk);

        return new self($storage->get($path), $storage->mimeType($path) ?: 'image/png', $note);
    }

    public function base64(): string
    {
        return base64_encode($this->data);
    }
}

<?php

namespace App\BrandKit;

/**
 * The file a kind of item carries: which files are accepted, and whether an
 * item is nothing without one (a logo) or can do without (a Google font).
 */
final readonly class FileSpec
{
    public const IMAGE = 'image';

    public const FONT = 'font';

    public const AUDIO = 'audio';

    /**
     * @param  list<string>  $extensions
     * @param  self::IMAGE|self::FONT|self::AUDIO  $type
     */
    public function __construct(
        public array $extensions,
        public string $type = self::IMAGE,
        public bool $required = true,
    ) {}

    /**
     * The browser's file picker filter.
     */
    public function accept(): string
    {
        return implode(',', array_map(fn (string $e) => ".{$e}", $this->extensions));
    }

    public function describe(): string
    {
        return strtoupper(implode(', ', $this->extensions));
    }
}

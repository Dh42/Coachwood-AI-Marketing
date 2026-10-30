<?php

namespace App\Images;

/**
 * The output sizes a request can ask for, by the longer side, roughly.
 * Each provider maps them to what it takes ("1K" is 1024 px on Gemini and
 * a 1024 or 1536 px edge on OpenAI).
 */
final class ImageSize
{
    public const OPTIONS = ['1K' => 'About 1,000 px', '2K' => 'About 2,000 px', '4K' => 'About 4,000 px'];

    public const DEFAULT = '1K';
}

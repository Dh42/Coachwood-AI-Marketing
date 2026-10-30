<?php

namespace App\Platforms;

/**
 * The pieces of copy a placement can take. Each placement says which it
 * uses and how long each may be (characters, or a count for hashtags).
 */
enum CopyField: string
{
    case PrimaryText = 'primary_text';
    case Headline = 'headline';
    case Description = 'description';
    case Caption = 'caption';
    case Hashtags = 'hashtags';

    public function label(): string
    {
        return match ($this) {
            self::PrimaryText => 'Primary text',
            self::Headline => 'Headline',
            self::Description => 'Description',
            self::Caption => 'Caption',
            self::Hashtags => 'Hashtags',
        };
    }

    /**
     * What the limit counts.
     */
    public function unit(): string
    {
        return $this === self::Hashtags ? 'hashtags' : 'characters';
    }
}

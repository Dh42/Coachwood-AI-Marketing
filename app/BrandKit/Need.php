<?php

namespace App\BrandKit;

/**
 * How much the brand kit needs a kind of item before the brand can generate
 * (the brief's "Required" column).
 */
enum Need: string
{
    case Required = 'required';
    case Recommended = 'recommended';
    case Optional = 'optional';
    case ForVideo = 'video';

    public function label(): string
    {
        return match ($this) {
            self::Required => 'Required',
            self::Recommended => 'Recommended',
            self::Optional => 'Optional',
            self::ForVideo => 'For video',
        };
    }
}

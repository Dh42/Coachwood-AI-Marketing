<?php

namespace App\Navigation;

enum NavSection: string
{
    /** Screens that work on the brand selected in the switcher. */
    case Brand = 'brand';

    /** Platform-wide screens shared by every brand. */
    case Platform = 'platform';

    public function label(): string
    {
        return match ($this) {
            self::Brand => 'Brand',
            self::Platform => 'Platform',
        };
    }
}

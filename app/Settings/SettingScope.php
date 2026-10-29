<?php

namespace App\Settings;

enum SettingScope: string
{
    case Platform = 'platform';
    case Brand = 'brand';
    /** One person's own value (such as what they're emailed about); scope_id is the user. */
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Platform => 'Platform default',
            self::Brand => 'Brand',
            self::User => 'Your own',
        };
    }
}

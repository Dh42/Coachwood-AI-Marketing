<?php

namespace App\Auth;

enum Role: string
{
    case Admin = 'admin';
    case Creator = 'creator';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Creator => 'Creator',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Everything: providers and API keys, budgets, users, brands and the platform library.',
            self::Creator => 'Generate, edit brand kits and prompts, download, and set their own work public or private.',
            self::Viewer => 'Browse public work and download it.',
        };
    }
}

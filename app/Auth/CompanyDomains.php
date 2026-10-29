<?php

namespace App\Auth;

use App\Settings\Settings;
use Illuminate\Support\Str;

/**
 * The email domains that can sign in with Google, from the
 * "Company email domains" platform setting.
 */
class CompanyDomains
{
    public function __construct(private Settings $settings) {}

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->settings->get('auth.allowed_domains');
    }

    public function includes(string $email): bool
    {
        return in_array(Str::lower(Str::after($email, '@')), $this->all(), true);
    }
}

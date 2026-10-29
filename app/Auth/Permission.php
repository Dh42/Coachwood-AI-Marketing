<?php

namespace App\Auth;

final readonly class Permission
{
    /**
     * @param  list<Role>  $roles  Roles that hold this permission.
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $roles,
    ) {}

    public function grantedTo(Role $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}

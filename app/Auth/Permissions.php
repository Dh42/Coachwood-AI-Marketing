<?php

namespace App\Auth;

use App\Models\Brand;
use Illuminate\Contracts\Auth\Access\Gate;
use InvalidArgumentException;

/**
 * Every permission in the platform, and which roles hold it. Core features
 * register theirs in AuthServiceProvider; core plugins register their own
 * (for example "approve audit suggestions") the same way.
 */
class Permissions
{
    /** @var array<string, Permission> */
    private array $permissions = [];

    public function __construct(private Gate $gate) {}

    /**
     * @param  list<Role>  $roles
     */
    public function define(string $key, string $label, array $roles): void
    {
        if (isset($this->permissions[$key])) {
            throw new InvalidArgumentException("Permission [{$key}] is already defined.");
        }

        $this->permissions[$key] = new Permission($key, $label, $roles);

        $this->gate->define($key, fn ($user, Brand|int|null $brand = null) => $user->hasPermission(
            $key,
            $brand instanceof Brand ? $brand->id : $brand,
        ));
    }

    public function find(string $key): ?Permission
    {
        return $this->permissions[$key] ?? null;
    }

    /**
     * @return array<string, Permission>
     */
    public function all(): array
    {
        return $this->permissions;
    }
}

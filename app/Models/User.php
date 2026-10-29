<?php

namespace App\Models;

use App\Auth\Permissions;
use App\Auth\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'google_id', 'email_verified_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function platformRole(): ?Role
    {
        return $this->roleAssignments->firstWhere('brand_id', RoleAssignment::PLATFORM)?->role;
    }

    /**
     * Platform-wide roles always count; a group role counts only when that
     * group is being checked.
     */
    public function hasPermission(string $key, ?int $brandId = null): bool
    {
        if ($this->isDisabled()) {
            return false;
        }

        $permission = app(Permissions::class)->find($key);

        return $permission !== null && $this->roleAssignments
            ->filter(fn (RoleAssignment $a) => $a->brand_id === RoleAssignment::PLATFORM || $a->brand_id === $brandId)
            ->contains(fn (RoleAssignment $a) => $permission->grantedTo($a->role));
    }

    /**
     * Groups this user can open: every group for anyone with a platform-wide
     * role, otherwise only the groups they hold a role in.
     *
     * @return Collection<int, Brand>
     */
    public function visibleBrands(): Collection
    {
        if ($this->isDisabled()) {
            return new Collection;
        }

        $query = Brand::query()->orderBy('name');

        if ($this->platformRole() === null) {
            $query->whereIn('id', $this->roleAssignments->pluck('brand_id'));
        }

        return $query->get();
    }

    public function hasAnyAccess(): bool
    {
        return ! $this->isDisabled() && $this->roleAssignments->isNotEmpty();
    }

    public function isAdmin(): bool
    {
        return ! $this->isDisabled() && $this->platformRole() === Role::Admin;
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function usesPassword(): bool
    {
        return $this->password !== null;
    }
}

<?php

namespace App\Auth;

use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Gives and removes roles, logging every change to the audit trail.
 */
class Roles
{
    public function roleIn(User $user, int $brandId = RoleAssignment::PLATFORM): ?Role
    {
        return $user->roleAssignments()->where('brand_id', $brandId)->first()?->role;
    }

    public function assign(User $user, Role $role, int $brandId = RoleAssignment::PLATFORM, ?User $by = null): void
    {
        $old = $this->roleIn($user, $brandId);

        if ($old === $role) {
            return;
        }

        $this->guardLastAdmin($user, $brandId, $old);

        $user->roleAssignments()->updateOrCreate(['brand_id' => $brandId], ['role' => $role]);
        $user->unsetRelation('roleAssignments');

        $this->log($user, $by, $brandId, $old, $role);
    }

    public function remove(User $user, int $brandId = RoleAssignment::PLATFORM, ?User $by = null): void
    {
        $old = $this->roleIn($user, $brandId);

        if ($old === null) {
            return;
        }

        $this->guardLastAdmin($user, $brandId, $old);

        $user->roleAssignments()->where('brand_id', $brandId)->delete();
        $user->unsetRelation('roleAssignments');

        $this->log($user, $by, $brandId, $old, null);
    }

    /**
     * Refuses to take the admin role away from the last active admin, which
     * would leave nobody able to manage users.
     */
    public function guardLastAdmin(User $user, int $brandId, ?Role $old): void
    {
        if ($brandId !== RoleAssignment::PLATFORM || $old !== Role::Admin || $user->isDisabled()) {
            return;
        }

        $otherAdmins = RoleAssignment::where('brand_id', RoleAssignment::PLATFORM)
            ->where('role', Role::Admin)
            ->where('user_id', '!=', $user->id)
            ->whereHas('user', fn ($q) => $q->whereNull('disabled_at'))
            ->exists();

        if (! $otherAdmins) {
            throw ValidationException::withMessages([
                'role' => "{$user->name} is the only admin. Make someone else an admin first.",
            ]);
        }
    }

    private function log(User $user, ?User $by, int $brandId, ?Role $old, ?Role $new): void
    {
        activity('users')
            ->causedBy($by)
            ->performedOn($user)
            ->event('role_changed')
            ->withProperties(['brand_id' => $brandId, 'old' => $old?->value, 'new' => $new?->value])
            ->log(sprintf('Changed %s from %s to %s', $user->email, $old?->label() ?? 'no access', $new?->label() ?? 'no access'));
    }
}

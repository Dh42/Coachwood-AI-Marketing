<?php

namespace App\Auth;

use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turning accounts off and on. A turned-off account keeps its roles, so
 * turning it back on restores the same access.
 */
class Accounts
{
    public function __construct(private Roles $roles) {}

    public function disable(User $user, User $by): void
    {
        if ($user->is($by)) {
            throw ValidationException::withMessages(['user' => 'You can\'t turn off your own account.']);
        }

        $this->roles->guardLastAdmin($user, RoleAssignment::PLATFORM, $user->platformRole());

        // A new remember token ends "stay signed in" on every device.
        $user->forceFill(['disabled_at' => now()])->setRememberToken(Str::random(60));
        $user->save();

        activity('users')->causedBy($by)->performedOn($user)->event('disabled')->log("Turned off {$user->email}");
    }

    public function enable(User $user, User $by): void
    {
        $user->forceFill(['disabled_at' => null])->save();

        activity('users')->causedBy($by)->performedOn($user)->event('enabled')->log("Turned on {$user->email}");
    }
}

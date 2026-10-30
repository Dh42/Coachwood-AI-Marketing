<?php

namespace App\Policies;

use App\Models\CreativeRequest;
use App\Models\User;

/**
 * Who may see and change a request: its creator, admins (who see every
 * request, public or private), and for public ones anyone who can see
 * the brand.
 */
class RequestPolicy
{
    public function view(User $user, CreativeRequest $request): bool
    {
        if (! $user->hasPermission('brands.view', $request->brand_id)) {
            return false;
        }

        return $request->isOwnedBy($user) || $request->public || $user->hasPermission('requests.view_all', $request->brand_id);
    }

    public function update(User $user, CreativeRequest $request): bool
    {
        return $request->isOwnedBy($user) ? $user->hasPermission('requests.create', $request->brand_id) : $user->hasPermission('requests.view_all', $request->brand_id);
    }
}

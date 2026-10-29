<?php

namespace App\Console\Commands;

use App\Auth\Role;
use App\Auth\Roles;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Makes someone a platform admin from the server. Used once to set up the
 * first admin; after that, admins change roles on the Users screen.
 */
#[Signature('users:grant-admin {email : Email of someone who has already signed in once}')]
#[Description('Make a user a platform admin')]
class GrantAdmin extends Command
{
    public function handle(Roles $roles): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->first();

        if (! $user) {
            $this->error('No user with that email. They need to sign in once first (with Google, or by accepting an invitation).');

            return self::FAILURE;
        }

        $roles->assign($user, Role::Admin);

        $this->info("{$user->name} ({$user->email}) is now an admin.");

        return self::SUCCESS;
    }
}

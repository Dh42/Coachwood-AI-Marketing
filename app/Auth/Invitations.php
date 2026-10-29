<?php

namespace App\Auth;

use App\Models\Invitation;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Invitations
{
    public function __construct(
        private Settings $settings,
        private Roles $roles,
    ) {}

    /**
     * Invites someone by email with a role. Company addresses are asked to
     * sign in with Google; anyone else sets a password from the link.
     */
    public function invite(string $email, Role $role, User $by, int $brandId = RoleAssignment::PLATFORM): Invitation
    {
        $email = Str::lower(trim($email));

        $existing = User::where('email', $email)->first();

        if ($existing && $this->roles->roleIn($existing, $brandId) !== null) {
            throw ValidationException::withMessages([
                'email' => "{$email} already has access. Change their role in the list instead.",
            ]);
        }

        $invitation = DB::transaction(function () use ($email, $role, $by, $brandId) {
            // A new invitation replaces any earlier one for the same person and scope.
            Invitation::open()->where(['email' => $email, 'brand_id' => $brandId])->update(['revoked_at' => now()]);

            return $this->issue(new Invitation([
                'email' => $email,
                'role' => $role,
                'brand_id' => $brandId,
                'invited_by' => $by->id,
            ]));
        });

        activity('users')
            ->causedBy($by)
            ->performedOn($invitation)
            ->event('invited')
            ->withProperties(['email' => $email, 'role' => $role->value, 'brand_id' => $brandId])
            ->log("Invited {$email} as {$role->label()}");

        return $invitation;
    }

    /**
     * Sends a fresh link (the old one stops working) with a new expiry date.
     */
    public function resend(Invitation $invitation, User $by): Invitation
    {
        DB::transaction(fn () => $this->issue($invitation));

        activity('users')
            ->causedBy($by)
            ->performedOn($invitation)
            ->event('invitation_resent')
            ->log("Resent the invitation to {$invitation->email}");

        return $invitation;
    }

    public function revoke(Invitation $invitation, User $by): void
    {
        $invitation->update(['revoked_at' => now()]);

        activity('users')
            ->causedBy($by)
            ->performedOn($invitation)
            ->event('invitation_revoked')
            ->log("Cancelled the invitation to {$invitation->email}");
    }

    /**
     * Applies every open, unexpired invitation for this user's email. Called
     * after each successful sign-in, by Google or password.
     */
    public function acceptOpenFor(User $user): void
    {
        $invitations = Invitation::open()
            ->where('email', Str::lower($user->email))
            ->where('expires_at', '>', now())
            ->get();

        foreach ($invitations as $invitation) {
            DB::transaction(function () use ($invitation, $user) {
                // Never overwrite a role given since the invitation was sent.
                if ($this->roles->roleIn($user, $invitation->brand_id) === null) {
                    $this->roles->assign($user, $invitation->role, $invitation->brand_id, $invitation->inviter);
                }

                $invitation->update(['accepted_at' => now()]);
            });

            activity('users')
                ->causedBy($user)
                ->performedOn($invitation)
                ->event('invitation_accepted')
                ->log("{$user->email} accepted the invitation as {$invitation->role->label()}");
        }
    }

    /**
     * Saves a new token and emails it. Callers run this in a transaction, so
     * if the email can't be sent nothing is saved and the old link still works.
     */
    private function issue(Invitation $invitation): Invitation
    {
        $token = Invitation::newToken();

        $invitation->fill([
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addDays((int) $this->settings->get('invitations.expiry_days')),
        ])->save();

        Notification::route('mail', $invitation->email)->notify(new InvitationNotification($invitation, $token));

        return $invitation;
    }
}

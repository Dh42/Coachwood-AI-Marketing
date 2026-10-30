<?php

namespace App\Livewire\Users;

use App\Auth\Accounts;
use App\Auth\Invitations;
use App\Auth\Role;
use App\Auth\Roles;
use App\Budgets\Budgets;
use App\Models\Brand;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Title('Users')]
class ManageUsers extends Component
{
    public string $search = '';

    public string $inviteEmail = '';

    public string $inviteRole = 'viewer';

    /** Bumped when a change is refused, so role dropdowns redraw with the saved value. */
    public int $redraw = 0;

    /** The person whose budget is being edited, and the amount typed. */
    public ?int $budgetFor = null;

    public string $budgetAmount = '';

    public function editBudget(int $userId, Budgets $budgets): void
    {
        $this->authorize('budgets.manage');

        $user = User::findOrFail($userId);
        $this->budgetFor = $user->id;
        $this->budgetAmount = (string) $budgets->person($user)['limit'];
        $this->resetErrorBag('budgetAmount');
    }

    public function keepBudget(): void
    {
        $this->reset('budgetFor', 'budgetAmount');
        $this->resetErrorBag('budgetAmount');
    }

    public function saveBudget(Budgets $budgets): void
    {
        $this->authorize('budgets.manage');

        $user = User::findOrFail($this->budgetFor);
        $this->validate(['budgetAmount' => ['required', 'numeric', 'min:0', 'max:1000000']], [], ['budgetAmount' => 'budget']);

        $budgets->setPersonBudget($user, (float) $this->budgetAmount, Auth::user());
        $this->keepBudget();
        $this->dispatch('saved', message: "Set {$user->name}'s monthly budget.");
    }

    public function defaultBudget(int $userId, Budgets $budgets): void
    {
        $this->authorize('budgets.manage');

        $user = User::findOrFail($userId);
        $budgets->setPersonBudget($user, null, Auth::user());
        $this->keepBudget();
        $this->dispatch('saved', message: "{$user->name} is back on the default budget.");
    }

    public function invite(Invitations $invitations): void
    {
        $this->authorize('users.manage');

        $this->validate([
            'inviteEmail' => ['required', 'email'],
            'inviteRole' => ['required', Rule::enum(Role::class)],
        ], [], ['inviteEmail' => 'email', 'inviteRole' => 'role']);

        try {
            $invitations->invite($this->inviteEmail, Role::from($this->inviteRole), Auth::user());
        } catch (ValidationException $e) {
            $this->addError('inviteEmail', collect($e->errors())->flatten()->first());

            return;
        } catch (Throwable $e) {
            Log::error('Invitation email failed', ['email' => $this->inviteEmail, 'exception' => $e]);
            $this->addError('inviteEmail', 'The invitation email couldn\'t be sent, so nothing was saved. Check the mail settings, then try again.');

            return;
        }

        $this->dispatch('saved', message: "Invitation sent to {$this->inviteEmail}.");
        $this->dispatch('invited');
        $this->reset('inviteEmail', 'inviteRole');
    }

    public function changeRole(int $userId, string $role, Roles $roles): void
    {
        $this->authorize('users.manage');

        $user = User::findOrFail($userId);

        $this->withFriendlyErrors(function () use ($roles, $user, $role) {
            $role === ''
                ? $roles->remove($user, by: Auth::user())
                : $roles->assign($user, Role::tryFrom($role) ?? abort(422), by: Auth::user());

            $this->dispatch('saved', message: "Updated {$user->name}'s role.");
        });
    }

    public function disable(int $userId, Accounts $accounts): void
    {
        $this->authorize('users.manage');

        $user = User::findOrFail($userId);

        $this->withFriendlyErrors(function () use ($accounts, $user) {
            $accounts->disable($user, Auth::user());
            $this->dispatch('saved', message: "{$user->name} can no longer sign in.");
        });
    }

    public function enable(int $userId, Accounts $accounts): void
    {
        $this->authorize('users.manage');

        $user = User::findOrFail($userId);
        $accounts->enable($user, Auth::user());

        $this->dispatch('saved', message: "{$user->name} can sign in again.");
    }

    public function resend(int $invitationId, Invitations $invitations): void
    {
        $this->authorize('users.manage');

        $invitation = Invitation::open()->findOrFail($invitationId);

        try {
            $invitations->resend($invitation, Auth::user());
        } catch (Throwable $e) {
            Log::error('Invitation email failed', ['email' => $invitation->email, 'exception' => $e]);
            $this->dispatch('failed', message: 'The email couldn\'t be sent. The old link still works. Check the mail settings, then try again.');

            return;
        }

        $this->dispatch('saved', message: "Sent a new link to {$invitation->email}.");
    }

    public function revoke(int $invitationId, Invitations $invitations): void
    {
        $this->authorize('users.manage');

        $invitation = Invitation::open()->findOrFail($invitationId);
        $invitations->revoke($invitation, Auth::user());

        $this->dispatch('saved', message: "Cancelled the invitation to {$invitation->email}.");
    }

    public function render(Budgets $budgets)
    {
        $search = trim($this->search);
        $users = User::with('roleAssignments')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->whereLike('name', "%{$search}%")->orWhereLike('email', "%{$search}%")))
            ->orderBy('name')
            ->get();

        return view('livewire.users.manage-users', [
            'brands' => Brand::pluck('name', 'id'),
            'users' => $users,
            'people' => $users->mapWithKeys(fn (User $u) => [$u->id => $budgets->person($u)]),
            'canBudget' => Auth::user()->can('budgets.manage'),
            'invitations' => Invitation::open()
                ->with('inviter')
                ->when($search !== '', fn ($q) => $q->whereLike('email', "%{$search}%"))
                ->latest()
                ->get(),
            'roles' => Role::cases(),
        ]);
    }

    /**
     * Shows rule failures (such as removing the last admin) as a message
     * instead of an error page.
     */
    private function withFriendlyErrors(callable $action): void
    {
        try {
            $action();
        } catch (ValidationException $e) {
            $this->redraw++;
            $this->dispatch('failed', message: collect($e->errors())->flatten()->first());
        }
    }
}

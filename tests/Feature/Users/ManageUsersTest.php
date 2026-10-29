<?php

use App\Auth\Role;
use App\Auth\Roles;
use App\Livewire\Users\ManageUsers;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    $this->admin = signInAs(Role::Admin, ['name' => 'Lesley', 'email' => 'lesley@biosteel.com']);
});

it('lists people and open invitations', function () {
    User::factory()->create(['name' => 'Bhavik', 'email' => 'bhavik@biosteel.com']);
    Invitation::factory()->create(['email' => 'clark@biosteel.com']);

    $this->get(route('users'))
        ->assertOk()
        ->assertSee('Bhavik')
        ->assertSee('Waiting to accept')
        ->assertSee('clark@biosteel.com');
});

it('invites someone and emails them', function () {
    Livewire::test(ManageUsers::class)
        ->set('inviteEmail', 'Clark@BioSteel.com')
        ->set('inviteRole', 'viewer')
        ->call('invite')
        ->assertHasNoErrors()
        ->assertDispatched('saved');

    $invitation = Invitation::sole();
    expect($invitation->email)->toBe('clark@biosteel.com')
        ->and($invitation->role)->toBe(Role::Viewer);
    Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class);
});

it('saves nothing and explains when the invitation email fails', function () {
    Notification::shouldReceive('route->notify')->andThrow(new RuntimeException('SMTP down'));

    Livewire::test(ManageUsers::class)
        ->set('inviteEmail', 'clark@biosteel.com')
        ->call('invite')
        ->assertHasErrors('inviteEmail');

    expect(Invitation::count())->toBe(0);
});

it('changes a role and records it in the audit trail', function () {
    $bhavik = User::factory()->withRole(Role::Viewer)->create();

    Livewire::test(ManageUsers::class)->call('changeRole', $bhavik->id, 'creator');

    expect($bhavik->fresh()->platformRole())->toBe(Role::Creator);

    $log = Activity::where('event', 'role_changed')->latest('id')->first();
    expect($log->causer->is($this->admin))->toBeTrue()
        ->and($log->properties['old'])->toBe('viewer')
        ->and($log->properties['new'])->toBe('creator');
});

it('removes access when set to No access', function () {
    $bhavik = User::factory()->withRole(Role::Viewer)->create();

    Livewire::test(ManageUsers::class)->call('changeRole', $bhavik->id, '');

    expect($bhavik->fresh()->platformRole())->toBeNull();
});

it('refuses to remove the last admin', function () {
    Livewire::test(ManageUsers::class)
        ->call('changeRole', $this->admin->id, 'viewer')
        ->assertDispatched('failed');

    expect($this->admin->fresh()->platformRole())->toBe(Role::Admin);
});

it('lets an admin step down once there is another admin', function () {
    User::factory()->admin()->create();

    Livewire::test(ManageUsers::class)->call('changeRole', $this->admin->id, 'viewer')->assertNotDispatched('failed');

    expect($this->admin->fresh()->platformRole())->toBe(Role::Viewer);
});

it('turns an account off and on again, keeping its role', function () {
    $bhavik = User::factory()->withRole(Role::Creator)->create();

    Livewire::test(ManageUsers::class)->call('disable', $bhavik->id);
    expect($bhavik->fresh()->isDisabled())->toBeTrue();

    Livewire::test(ManageUsers::class)->call('enable', $bhavik->id);
    expect($bhavik->fresh()->isDisabled())->toBeFalse()
        ->and($bhavik->fresh()->platformRole())->toBe(Role::Creator);
});

it('won\'t let an admin turn off their own account', function () {
    Livewire::test(ManageUsers::class)->call('disable', $this->admin->id)->assertDispatched('failed');

    expect($this->admin->fresh()->isDisabled())->toBeFalse();
});

it('does not count a turned-off admin as the other admin', function () {
    User::factory()->admin()->disabled()->create();

    Livewire::test(ManageUsers::class)->call('changeRole', $this->admin->id, 'viewer')->assertDispatched('failed');
});

it('cancels and resends invitations', function () {
    $invitation = Invitation::factory()->create();

    Livewire::test(ManageUsers::class)->call('resend', $invitation->id)->assertDispatched('saved');
    Notification::assertSentTimes(InvitationNotification::class, 1);

    Livewire::test(ManageUsers::class)->call('revoke', $invitation->id);
    expect($invitation->fresh()->revoked_at)->not->toBeNull();
});

it('checks permission on every action, not just the page', function () {
    $viewer = User::factory()->withRole(Role::Viewer)->create();
    $this->actingAs($viewer);

    Livewire::test(ManageUsers::class)->call('changeRole', $viewer->id, 'admin')->assertForbidden();

    expect($viewer->fresh()->platformRole())->toBe(Role::Viewer);
});

it('filters by name or email', function () {
    User::factory()->create(['name' => 'Bhavik Patel', 'email' => 'bhavik@biosteel.com']);
    User::factory()->create(['name' => 'Clark Kent', 'email' => 'clark@canadianprotein.com']);

    Livewire::test(ManageUsers::class)
        ->set('search', 'canadianprotein')
        ->assertSee('Clark Kent')
        ->assertDontSee('Bhavik Patel');
});

it('shows the roles people hold in individual groups', function () {
    $brand = makeBrand(['name' => 'BioSteel Support']);
    $bhavik = User::factory()->create(['name' => 'Bhavik']);
    app(Roles::class)->assign($bhavik, Role::Creator, $brand->id);

    Livewire::test(ManageUsers::class)->assertSee('Creator in BioSteel');
});

it('turns away a role that doesn\'t exist without an error page', function () {
    signInAs(Role::Admin);
    $user = User::factory()->create();

    Livewire::test(ManageUsers::class)->call('changeRole', $user->id, 'superuser')->assertStatus(422);

    expect($user->fresh()->platformRole())->toBeNull();
});

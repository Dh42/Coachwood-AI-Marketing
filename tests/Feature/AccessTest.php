<?php

use App\Auth\Role;
use App\Auth\Roles;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

const BRAND_SCREENS = ['brand-settings', 'products', 'brand-kit', 'requests'];

it('shows a no-access page, with only account and sign-out, to someone without a role', function () {
    makeBrand();
    signInAs(null, ['name' => 'Nora Newcomer']);

    $this->get('/')
        ->assertOk()
        ->assertSee('have access yet')
        ->assertSee('Nora Newcomer')
        ->assertSee('Sign out')
        ->assertDontSee('New brand')
        ->assertDontSee(route('users'));
});

it('lets every role open brand screens, and only admins open platform screens', function (Role $role, bool $platform) {
    $brand = makeBrand();
    signInAs($role);

    foreach (BRAND_SCREENS as $screen) {
        $this->get(route($screen, $brand))->assertOk();
    }

    foreach (['connections', 'platforms', 'models', 'users', 'settings', 'activity', 'scheduled-tasks', 'brands.create'] as $screen) {
        $this->get(route($screen))->assertStatus($platform ? 200 : 403);
    }
})->with([
    'admin' => [Role::Admin, true],
    'creator' => [Role::Creator, false],
    'viewer' => [Role::Viewer, false],
]);

it('gives a brand-only role access to that brand and no other', function () {
    $mine = makeBrand(['name' => 'Mine']);
    $other = makeBrand(['name' => 'Other']);
    $user = signInAs(null);
    app(Roles::class)->assign($user, Role::Creator, $mine->id);

    $this->get(route('brand-settings', $mine))->assertOk()->assertDontSee('Other');
    $this->get(route('brand-settings', $other))->assertForbidden();
    $this->get('/')->assertRedirect(route('requests', $mine));
});

it('hides platform screens from the sidebar for non-admins', function () {
    $brand = makeBrand();
    signInAs(Role::Creator);

    $this->get(route('brand-settings', $brand))
        ->assertDontSee(route('users'))
        ->assertDontSee(route('settings'))
        ->assertDontSee(route('connections'));
});

it('forbids everything to someone with no role', function () {
    $brand = makeBrand();
    signInAs(null);

    $this->get(route('brand-settings', $brand))->assertForbidden();
    $this->get(route('users'))->assertForbidden();
    $this->get(route('brands.create'))->assertForbidden();
});

it('signs out a user whose account was turned off mid-session', function () {
    $brand = makeBrand();
    $user = signInAs(Role::Admin);
    $user->forceFill(['disabled_at' => now()])->save();

    $this->get(route('brand-settings', $brand))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('gives a turned-off account no permissions even if it still has a role', function () {
    $user = User::factory()->admin()->disabled()->create();

    expect($user->can('users.manage'))->toBeFalse()
        ->and($user->isAdmin())->toBeFalse()
        ->and($user->visibleBrands())->toBeEmpty();
});

it('only lets admins open Horizon', function () {
    signInAs(Role::Viewer);
    expect(Gate::allows('viewHorizon', [auth()->user()]))->toBeFalse();

    signInAs(Role::Admin);
    expect(Gate::allows('viewHorizon', [auth()->user()]))->toBeTrue();
});

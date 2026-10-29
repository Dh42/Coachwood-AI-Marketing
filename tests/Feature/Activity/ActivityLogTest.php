<?php

use App\Auth\Role;
use App\Auth\Roles;
use App\Livewire\Activity\ActivityLog;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = signInAs(Role::Admin, ['name' => 'Lesley']);
});

it('lists recorded activity, newest first, with who did it', function () {
    $bhavik = User::factory()->create(['name' => 'Bhavik', 'email' => 'bhavik@biosteel.com']);
    app(Roles::class)->assign($bhavik, Role::Viewer, by: $this->admin);
    $this->travel(1)->minutes();
    app(Roles::class)->assign($bhavik, Role::Creator, by: $this->admin);

    Livewire::test(ActivityLog::class)
        ->assertSeeInOrder(['from Viewer to Creator', 'from no access to Viewer'])
        ->assertSee('Lesley')
        ->assertSee('Users and roles');
});

it('filters by type, person, date and words', function () {
    $other = User::factory()->create(['name' => 'Other Admin']);
    activity('settings')->causedBy($this->admin)->log('Changed "Reply delay"');
    activity('auth')->causedBy($other)->log('Signed in with Google');

    Livewire::test(ActivityLog::class)
        ->set('category', 'settings')->assertSee('Reply delay')->assertDontSee('Signed in with Google')
        ->set('category', '')->set('person', (string) $other->id)->assertSee('Signed in with Google')->assertDontSee('Reply delay')
        ->call('clearFilters')->set('search', 'delay')->assertSee('Reply delay')->assertDontSee('Signed in')
        ->call('clearFilters')->set('from', now()->addDay()->toDateString())->assertSee('Nothing matches');
});

it('shows the details recorded with an entry', function () {
    activity('settings')->causedBy($this->admin)->withProperties(['old' => 7, 'new' => 14])->log('Changed "Invitation links expire after"');

    Livewire::test(ActivityLog::class)->assertSee('old')->assertSee('14');
});

it('is for admins only', function () {
    signInAs(Role::Creator);

    $this->get(route('activity'))->assertForbidden();
});

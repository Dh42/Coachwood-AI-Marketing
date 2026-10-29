<?php

use App\Auth\Role;
use App\Navigation\Navigation;
use App\Navigation\NavItem;
use App\Navigation\NavSection;

it('sends guests to the sign-in page', function () {
    $this->get('/')->assertRedirect('/login');
});

it('sends signed-in users to the first screen of their current brand', function () {
    $brand = makeBrand();
    signInAs(Role::Viewer);

    $this->get('/')->assertRedirect(route('products', $brand));
});

it('explains there are no brands yet, with a create button for admins', function () {
    signInAs(Role::Admin);

    $this->get('/')->assertOk()->assertSee('No brands yet')->assertSee(route('brands.create'));
});

it('renders every brand screen inside the app shell', function (string $route) {
    $brand = makeBrand(['name' => 'Canadian Protein']);
    signInAs(Role::Admin);
    $item = app(Navigation::class)->find($route);

    $this->get(route($route, $brand))
        ->assertOk()
        ->assertSee("<title>{$item->label}", escape: false)
        ->assertSee('aria-current="page"', escape: false)
        ->assertSee('Canadian Protein');
})->with(['products', 'brand-settings']);

it('renders every platform screen for an admin', function (string $route) {
    signInAs(Role::Admin);

    $this->get(route($route))->assertOk()->assertSee('aria-current="page"', escape: false);
})->with(['connections', 'users', 'settings', 'activity', 'scheduled-tasks']);

it('has a sidebar item for every screen and nothing else', function () {
    $keys = collect(NavSection::cases())
        ->flatMap(fn (NavSection $section) => app(Navigation::class)->section($section)->pluck('key'))
        ->sort()->values()->all();

    expect($keys)->toBe(['activity', 'brand-settings', 'connections', 'products', 'scheduled-tasks', 'settings', 'users']);
});

it('lists brand screens before platform screens in the sidebar', function () {
    $brand = makeBrand(['name' => 'Canadian Protein']);
    signInAs(Role::Admin);

    $this->get(route('brand-settings', $brand))->assertSeeInOrder(['Canadian Protein', 'Platform', 'Connections', 'Users']);
});

it('keeps you on the same screen when switching brands', function () {
    $a = makeBrand(['name' => 'Alpha']);
    $b = makeBrand(['name' => 'Beta']);
    signInAs(Role::Admin);

    $this->get(route('brand-settings', $a))->assertSee(route('brand-settings', $b));
});

it('remembers the last brand opened', function () {
    makeBrand(['name' => 'Alpha']);
    $b = makeBrand(['name' => 'Beta']);
    signInAs(Role::Admin);

    $this->get(route('brand-settings', $b));

    $this->get('/')->assertRedirect(route('products', $b));
});

it('answers the health check', function () {
    $this->get('/up')->assertOk();
});

it('leaves out a sidebar item whose page isn\'t registered, instead of breaking every page', function () {
    app(Navigation::class)->add(new NavItem('ghost', 'Ghost', 'no-such-route', 'heroicon-o-bolt', NavSection::Brand, 1, 'brands.view'));
    $brand = makeBrand();
    signInAs();

    $this->get(route('brand-settings', $brand))->assertOk()->assertDontSee('Ghost');
});

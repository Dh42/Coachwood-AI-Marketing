<?php

use App\Auth\Role;
use App\Livewire\Connections\CreateConnection;
use Livewire\Livewire;

/**
 * Text escaped twice shows up on screen as "&quot;" or "&#039;".
 * Legitimate output escapes once, so "&amp;" followed by an entity name
 * never appears.
 */
function assertNotDoubleEscaped(string $html): void
{
    expect($html)->not->toMatch('/&amp;(quot|#0?39|amp|lt|gt);/');
}

it('never shows double-escaped characters on any screen', function (string $route, bool $brand) {
    $b = makeBrand();
    signInAs(Role::Admin);

    assertNotDoubleEscaped($this->get($brand ? route($route, $b) : route($route))->getContent());
})->with([
    ['brand-settings', true], ['products', true], ['brand-kit', true], ['requests', true], ['requests.create', true],
    ['brands.create', false], ['connections', false], ['connections.create', false], ['platforms', false], ['models', false], ['users', false], ['settings', false], ['activity', false], ['scheduled-tasks', false], ['account', false], ['notices', false], ['spend', false],
]);

it('shows the example connection name with plain quotes', function () {
    fakePlugin();
    signInAs(Role::Admin);

    $page = Livewire::test(CreateConnection::class)->call('choose', 'fake');
    assertNotDoubleEscaped($page->html());
    $page->assertSee('“BioSteel Fake”');
});

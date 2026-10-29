<?php

use App\Auth\Role;
use App\Livewire\Connections\ConnectionsIndex;
use App\Livewire\Connections\CreateConnection;
use App\Livewire\Connections\ShowConnection;
use App\Models\Connection;
use App\Plugins\ConnectionMethod;
use App\Plugins\PluginRegistry;
use Livewire\Livewire;

beforeEach(function () {
    $this->plugin = fakePlugin();
    $this->admin = signInAs(Role::Admin);
});

it('adds a field-based connection for a brand and checks it', function () {
    $brand = makeBrand(['name' => 'BioSteel']);

    Livewire::test(CreateConnection::class)
        ->call('choose', 'fake')
        ->set('name', 'Acme Fake')
        ->set('brand', (string) $brand->id)
        ->set('fields.account', 'acme')
        ->set('fields.api_key', 'sk-1')
        ->call('save')
        ->assertRedirect(route('connections.show', Connection::sole()));

    expect(Connection::sole()->status)->toBe(Connection::STATUS_CONNECTED)
        ->and(Connection::sole()->brand_id)->toBe($brand->id);

    $this->get(route('connections'))->assertOk()->assertSee('BioSteel')->assertSee('Acme Fake');
});

it('shows field errors in place, and refuses a brand that doesn\'t exist', function () {
    Livewire::test(CreateConnection::class)->call('choose', 'fake')->set('name', 'X')->call('save')->assertHasErrors(['fields.account', 'fields.api_key']);
    Livewire::test(CreateConnection::class)->call('choose', 'fake')->set('name', 'X')->set('brand', '999')->call('save')->assertHasErrors('brand');
    expect(Connection::count())->toBe(0);
});

it('never sends a saved secret back to the page', function () {
    $connection = Connection::factory()->create(['plugin' => 'fake', 'config' => ['account' => 'acme'], 'secrets' => ['api_key' => 'sk-very-secret']]);

    Livewire::test(ShowConnection::class, ['connection' => $connection])
        ->assertSet('fields.api_key', '')
        ->assertDontSee('sk-very-secret')
        ->assertSee('Leave blank to keep it');
});

it('moves a connection between brands and shared from its page', function () {
    $brand = makeBrand();
    $connection = Connection::factory()->create(['plugin' => 'fake']);

    Livewire::test(ShowConnection::class, ['connection' => $connection])->set('brand', (string) $brand->id)->call('save')->assertHasNoErrors();
    expect($connection->fresh()->brand_id)->toBe($brand->id);

    Livewire::test(ShowConnection::class, ['connection' => $connection])->set('brand', '')->call('save')->assertHasNoErrors();
    expect($connection->fresh()->brand_id)->toBeNull();
});

it('signs in an OAuth connection and stores the token', function () {
    fakePlugin('oauthy', ConnectionMethod::OAuth);
    $connection = Connection::factory()->create(['plugin' => 'oauthy', 'config' => ['account' => 'acme'], 'secrets' => [], 'status' => Connection::STATUS_PENDING]);

    $redirect = $this->get(route('connections.authorize', $connection))->assertRedirect();
    parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

    $this->get(route('connections.oauth.callback', ['state' => $query['state'], 'code' => 'abc']))
        ->assertRedirect(route('connections.show', $connection));

    expect($connection->fresh()->secret('access_token'))->toBe('token-for-abc')
        ->and($connection->fresh()->status)->toBe(Connection::STATUS_CONNECTED);
});

it('rejects an OAuth callback with an unknown or reused state', function () {
    $this->get(route('connections.oauth.callback', ['state' => 'made-up', 'code' => 'abc']))
        ->assertRedirect(route('connections'))
        ->assertSessionHasErrors('oauth');
});

it('explains when access wasn\'t approved', function () {
    fakePlugin('oauthy', ConnectionMethod::OAuth);
    $connection = Connection::factory()->create(['plugin' => 'oauthy', 'config' => [], 'secrets' => [], 'status' => Connection::STATUS_PENDING]);
    $redirect = $this->get(route('connections.authorize', $connection));
    parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

    $this->get(route('connections.oauth.callback', ['state' => $query['state'], 'error' => 'access_denied']))
        ->assertSessionHasErrors('oauth');

    expect($connection->fresh()->secrets)->toBe([]);
});

it('switches plugins off and on from the connections page', function () {
    Livewire::test(ConnectionsIndex::class)->call('setPlugin', 'fake', false);
    expect(app(PluginRegistry::class)->isEnabled('fake'))->toBeFalse();

    Livewire::test(ConnectionsIndex::class)->call('setPlugin', 'fake', true);
    expect(app(PluginRegistry::class)->isEnabled('fake'))->toBeTrue();
});

it('keeps connections admin-only', function () {
    signInAs(Role::Creator);

    $this->get(route('connections'))->assertForbidden();
    $this->get(route('connections.create'))->assertForbidden();
});

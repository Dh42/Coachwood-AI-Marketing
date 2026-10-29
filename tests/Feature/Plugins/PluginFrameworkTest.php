<?php

use App\Auth\Role;
use App\Models\Connection;
use App\Plugins\Connections;
use App\Plugins\IntegrationPlugin;
use App\Plugins\PluginRegistry;
use App\Plugins\PluginStates;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->plugin = fakePlugin();
    $this->admin = signInAs(Role::Admin);
    $this->connections = app(Connections::class);
    $this->brand = makeBrand();
});

function fakeConnection(string $name = 'Fake One'): Connection
{
    return app(Connections::class)->create('fake', $name, ['account' => 'acme', 'api_key' => 'sk-secret'], test()->admin);
}

it('stores secrets encrypted, keeps them out of logs and arrays, and checks the connection', function () {
    $connection = fakeConnection();

    expect($connection->config)->toBe(['account' => 'acme'])
        ->and($connection->secret('api_key'))->toBe('sk-secret')
        ->and($connection->status)->toBe(Connection::STATUS_CONNECTED)
        ->and($connection->toArray())->not->toHaveKey('secrets')
        ->and(DB::table('connections')->value('secrets'))->not->toContain('sk-secret')
        ->and(Activity::all()->pluck('properties')->toJson())->not->toContain('sk-secret');
});

it('records a failed check as a problem with the reason', function () {
    $this->plugin->healthy = false;

    expect(fakeConnection()->fresh())
        ->status->toBe(Connection::STATUS_ERROR)
        ->last_error->toBe('Service says no');
});

it('requires unique names so people can tell connections apart', function () {
    fakeConnection('Same');
    fakeConnection('Same');
})->throws(ValidationException::class);

it('keeps a saved secret when the field is left blank on edit', function () {
    $connection = fakeConnection();

    $this->connections->update($connection, 'Renamed', ['account' => 'acme2', 'api_key' => ''], $this->admin);

    expect($connection->fresh())
        ->name->toBe('Renamed')
        ->and($connection->fresh()->secret('api_key'))->toBe('sk-secret')
        ->and($connection->fresh()->config('account'))->toBe('acme2');
});

it('forgets tokens on disconnect but keeps advanced app credentials', function () {
    $connection = $this->connections->create('fake', 'Fake', ['account' => 'a', 'api_key' => 'k', 'client_secret' => 'app-secret'], $this->admin);

    $this->connections->disconnect($connection, $this->admin);

    expect($connection->fresh()->secrets)->toBe(['client_secret' => 'app-secret'])
        ->and($connection->fresh()->status)->toBe(Connection::STATUS_PENDING);
});

it('belongs to one brand or is shared, and says so', function () {
    $own = $this->connections->create('fake', 'Own', ['account' => 'a', 'api_key' => 'k'], $this->admin, $this->brand);
    $shared = fakeConnection('Shared');
    $other = makeBrand();

    expect($own->isFor($this->brand))->toBeTrue()->and($own->isFor($other))->toBeFalse()
        ->and($shared->isFor($other))->toBeTrue()->and($shared->isShared())->toBeTrue()
        ->and(Connection::forBrand($other)->pluck('name')->all())->toBe(['Shared'])
        ->and(Connection::pickerOptions('fake', $this->brand->id, 'llm.effort'))->toBe([(string) $own->id => 'Own', (string) $shared->id => 'Shared']);

    $this->connections->setBrand($own, null, $this->admin);
    expect($own->fresh()->isShared())->toBeTrue()
        ->and(Activity::where('event', 'connection_updated')->latest('id')->first()->description)->toBe('Set "Own" to shared by every brand');
});

it('won\'t remove a connection something still uses', function () {
    $connection = fakeConnection();
    $this->connections->usedBy(fn (Connection $c) => $c->is($connection) ? ['the BioSteel store'] : []);

    expect($this->connections->usesOf($connection))->toBe(['the BioSteel store']);
    $this->connections->delete($connection, $this->admin);
})->throws(ValidationException::class);

it('removes an unused connection', function () {
    $connection = fakeConnection();

    $this->connections->delete($connection, $this->admin);

    expect(Connection::count())->toBe(0);
});

it('refuses to connect a plugin that is switched off', function () {
    app(PluginStates::class)->set('fake', false, $this->admin);

    fakeConnection();
})->throws(ValidationException::class);

it('lists the enabled plugins providing a capability', function () {
    $registry = app(PluginRegistry::class);

    expect($registry->providing(IntegrationPlugin::class)->keys()->all())->toContain('fake');

    app(PluginStates::class)->set('fake', false, $this->admin);
    expect($registry->providing(IntegrationPlugin::class)->keys()->all())->not->toContain('fake');
});

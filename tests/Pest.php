<?php

use App\Auth\Role;
use App\Llm\LlmManager;
use App\Models\Brand;
use App\Models\User;
use App\Plugins\ConnectionMethod;
use App\Plugins\PluginRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLlm;
use Tests\Support\FakePlugin;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', '../plugins/*/tests');

function signInAs(?Role $role = Role::Admin, array $attributes = []): User
{
    $factory = User::factory()->google();
    $user = ($role ? $factory->withRole($role) : $factory)->create($attributes);

    test()->actingAs($user);

    return $user;
}

function makeBrand(array $attributes = []): Brand
{
    return Brand::factory()->create($attributes);
}

/**
 * Registers a fake integration plugin and returns it.
 */
function fakePlugin(string $key = 'fake', ConnectionMethod $method = ConnectionMethod::Fields): FakePlugin
{
    $plugin = new FakePlugin($key, $method);
    app(PluginRegistry::class)->register($plugin);

    return $plugin;
}

/**
 * Swaps the AI provider for a scripted fake and returns it.
 */
function fakeLlm(mixed ...$replies): FakeLlm
{
    $llm = new FakeLlm($replies);
    app(LlmManager::class)->register('anthropic', $llm);

    return $llm;
}

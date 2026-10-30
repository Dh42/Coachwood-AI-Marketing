<?php

use App\Auth\Role;
use App\Livewire\Settings\SettingsForm;
use App\Llm\Effort;
use App\Settings\SettingControl;
use App\Settings\SettingDefinition;
use App\Settings\Settings;
use App\Settings\SettingScope;
use App\Settings\SettingsRegistry;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = signInAs(Role::Admin);
    $this->brand = makeBrand(['name' => 'BioSteel']);
    $this->form = fn () => Livewire::test(SettingsForm::class, ['scope' => SettingScope::Brand, 'scopeId' => $this->brand->id, 'ability' => 'brands.manage', 'sections' => ['AI models'], 'batch' => true]);
});

it('shows each brand setting\'s default for all brands, to admins only', function () {
    ($this->form)()->assertSee('Default for all brands')->assertSee('Save for all brands');

    signInAs(Role::Creator);
    ($this->form)()->assertDontSee('Default for all brands');
});

it('changes the default for all brands from beside a brand\'s value, keeping the page\'s unsaved changes', function () {
    ($this->form)()
        ->set('values.llm__default_model', 'claude-sonnet-5-5')
        ->set('platformValues.llm__effort', 'low')
        ->call('savePlatform', 'llm__effort')->assertHasNoErrors()->assertDispatched('saved')
        ->assertSet('values.llm__default_model', 'claude-sonnet-5-5')
        ->assertSet('values.llm__effort', 'low');

    expect(app(Settings::class)->get('llm.effort'))->toBe('low')
        ->and(app(Settings::class)->get('llm.default_model', $this->brand->id))->not->toBe('claude-sonnet-5-5');
});

it('keeps a brand\'s own value when the default for all brands changes', function () {
    app(Settings::class)->set('llm.effort', 'max', SettingScope::Brand, $this->brand->id, $this->admin);

    ($this->form)()->set('platformValues.llm__effort', 'low')->call('savePlatform', 'llm__effort');

    expect(app(Settings::class)->get('llm.effort', $this->brand->id))->toBe('max');
});

it('lets only admins change the default for all brands', function () {
    $form = ($this->form)();
    signInAs(Role::Creator);
    $form->set('platformValues.llm__effort', 'low')->call('savePlatform', 'llm__effort')->assertForbidden();
});

it('says a value in a few words', function () {
    app(SettingsRegistry::class)->define(new SettingDefinition('test.secret', 'A secret', '', SettingControl::Secret, null));
    $describe = fn (string $key, mixed $value) => app(SettingsRegistry::class)->get($key)->describe($value);

    expect($describe('invitations.expiry_days', 7))->toBe('7 days')
        ->and($describe('auth.allowed_domains', ['a', 'b', 'c', 'd', 'e']))->toBe('a, b, c and 2 more')
        ->and($describe('auth.allowed_domains', []))->toBe('none')
        ->and($describe('test.secret', 'secret'))->toBe('saved')
        ->and($describe('llm.effort', 'low'))->toBe(Effort::OPTIONS['low']);
});

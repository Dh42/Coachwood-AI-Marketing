<?php

use App\Auth\Role;
use App\Livewire\Settings\SettingsForm;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = signInAs(Role::Admin);
    $this->brand = makeBrand(['name' => 'BioSteel']);
    $this->form = fn () => Livewire::test(SettingsForm::class, ['scope' => SettingScope::Brand, 'scopeId' => $this->brand->id, 'ability' => 'brands.manage', 'batch' => true]);
    $this->get = fn (string $key) => app(Settings::class)->get($key, $this->brand->id);
});

it('shows one floating save bar on the brand settings page instead of a Save button per setting', function () {
    $this->get(route('brand-settings', $this->brand))->assertOk()
        ->assertSee('aria-label="Save changes"', false)->assertSee('No unsaved changes')
        ->assertDontSee('>Save</button>', false);

    // A form without the bar keeps its Save buttons.
    Livewire::test(SettingsForm::class, ['scope' => SettingScope::Brand, 'scopeId' => $this->brand->id, 'ability' => 'brands.manage', 'section' => 'AI models'])
        ->assertSeeHtml('wire:target="save(\'llm__effort\')"');
});

it('saves every changed setting together', function () {
    ($this->form)()
        ->assertSet('saved.llm__effort', 'high')
        ->set('values.llm__effort', 'low')->set('values.llm__review_model', 'claude-sonnet-5-5')
        ->call('saveAll', ['llm__effort', 'llm__review_model'])
        ->assertReturned(true)->assertHasNoErrors()->assertDispatched('saved', message: 'Saved 2 settings.')
        ->assertSet('saved.llm__effort', 'low');

    expect(($this->get)('llm.effort'))->toBe('low')->and(($this->get)('llm.review_model'))->toBe('claude-sonnet-5-5');
});

it('saves none of them when one is invalid, and shows the error on that setting', function () {
    ($this->form)()
        ->set('values.llm__effort', 'low')->set('values.llm__review_model', 'no-such-model')
        ->call('saveAll', ['llm__effort', 'llm__review_model'])
        ->assertReturned(false)->assertHasErrors('values.llm__review_model')->assertHasNoErrors('values.llm__effort')->assertNotDispatched('saved')
        ->assertSeeHtml('data-save-error');

    expect(($this->get)('llm.effort'))->toBe('high');
});

it('discards unsaved changes, and keeps them when another setting is put back to its inherited value', function () {
    app(Settings::class)->set('llm.review_model', 'claude-sonnet-5-5', SettingScope::Brand, $this->brand->id, $this->admin);

    ($this->form)()
        ->set('values.llm__effort', 'low')
        ->call('resetToDefault', 'llm__review_model')
        ->assertSet('values.llm__effort', 'low')->assertSet('values.llm__review_model', 'claude-opus-5-5')
        ->call('discard')
        ->assertSet('values.llm__effort', 'high');

    expect(($this->get)('llm.effort'))->toBe('high');
});

it('refuses saving from the bar without permission, and only saves settings of this form', function () {
    ($this->form)()->call('saveAll', ['platform__timezone'])->assertNotFound();

    signInAs(Role::Viewer);
    ($this->form)()->set('values.llm__effort', 'low')->call('saveAll', ['llm__effort'])->assertForbidden();
    expect(($this->get)('llm.effort'))->toBe('high');
});

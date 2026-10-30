<?php

use App\Auth\Role;
use App\Livewire\Settings\SettingsForm;
use App\Models\Setting;
use App\Settings\SettingControl;
use App\Settings\SettingDefinition;
use App\Settings\Settings;
use App\Settings\SettingScope;
use App\Settings\SettingsRegistry;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = signInAs(Role::Admin);
    $this->settings = app(Settings::class);
});

it('falls back to the code default when nothing is saved', function () {
    $resolved = $this->settings->resolve('invitations.expiry_days');

    expect($resolved->value)->toBe(7)->and($resolved->isDefault())->toBeTrue();
});

it('saves a platform value and logs the old and new value', function () {
    $this->settings->set('invitations.expiry_days', 14, SettingScope::Platform, 0, $this->admin);

    expect($this->settings->get('invitations.expiry_days'))->toBe(14);

    $log = Activity::where('log_name', 'settings')->sole();
    expect($log->causer->is($this->admin))->toBeTrue()
        ->and($log->properties['old'])->toBe(7)
        ->and($log->properties['new'])->toBe(14);
});

it('rejects invalid values with the setting\'s own rules', function () {
    $this->settings->set('invitations.expiry_days', 500, SettingScope::Platform, 0, $this->admin);
})->throws(ValidationException::class);

it('tidies company domains before saving', function () {
    $this->settings->set('auth.allowed_domains', [' @BioSteel.com ', 'biosteel.com', 'coachwoodgroup.com', ''], SettingScope::Platform, 0, $this->admin);

    expect($this->settings->get('auth.allowed_domains'))->toBe(['biosteel.com', 'coachwoodgroup.com']);
});

it('rejects something that isn\'t a domain', function () {
    $this->settings->set('auth.allowed_domains', ['not a domain'], SettingScope::Platform, 0, $this->admin);
})->throws(ValidationException::class);

it('resolves the person\'s own, then the brand, then the platform, then the default', function () {
    app(SettingsRegistry::class)->define(new SettingDefinition(
        key: 'test.layered', label: 'Layered', description: '', control: SettingControl::Number, default: 1,
        scopes: [SettingScope::Platform, SettingScope::Brand, SettingScope::User],
        rules: ['value' => ['integer']],
    ));

    expect($this->settings->get('test.layered', brandId: 5, userId: 9))->toBe(1);

    $this->settings->set('test.layered', 2, SettingScope::Platform, 0, $this->admin);
    expect($this->settings->get('test.layered', brandId: 5, userId: 9))->toBe(2);

    $this->settings->set('test.layered', 3, SettingScope::Brand, 5, $this->admin);
    expect($this->settings->get('test.layered', brandId: 5, userId: 9))->toBe(3)
        ->and($this->settings->get('test.layered', brandId: 6))->toBe(2);

    $this->settings->set('test.layered', 4, SettingScope::User, 9, $this->admin);
    $resolved = $this->settings->resolve('test.layered', brandId: 5, userId: 9);
    expect($resolved->value)->toBe(4)->and($resolved->source)->toBe(SettingScope::User);
});

it('refuses a scope the setting doesn\'t allow', function () {
    $this->settings->set('invitations.expiry_days', 3, SettingScope::Brand, 1, $this->admin);
})->throws(InvalidArgumentException::class);

it('resets a value so it inherits again', function () {
    $this->settings->set('invitations.expiry_days', 14, SettingScope::Platform, 0, $this->admin);
    $this->settings->reset('invitations.expiry_days', SettingScope::Platform, 0, $this->admin);

    expect($this->settings->get('invitations.expiry_days'))->toBe(7)
        ->and(Setting::count())->toBe(0);
});

it('stores secrets encrypted and never sends them back', function () {
    app(SettingsRegistry::class)->define(new SettingDefinition('test.secret', 'A secret', '', SettingControl::Secret, null, rules: ['value' => ['nullable', 'string']]));
    $this->settings->set('test.secret', 'sk-ant-secret', SettingScope::Platform, 0, $this->admin);

    expect($this->settings->get('test.secret'))->toBe('sk-ant-secret')
        ->and(Setting::sole()->value)->not->toContain('sk-ant-secret')
        ->and(Activity::where('log_name', 'settings')->sole()->properties->toJson())->not->toContain('sk-ant-secret');

    // Blank keeps what's saved.
    $this->settings->set('test.secret', '', SettingScope::Platform, 0, $this->admin);
    expect($this->settings->get('test.secret'))->toBe('sk-ant-secret');
});

it('renders each platform setting with its control on the Settings page', function () {
    $this->get(route('settings'))
        ->assertOk()
        ->assertSee('Company email domains')
        ->assertSee('Invitation links expire after')
        ->assertSee('Time zone')
        ->assertSee('America/Toronto');
});

it('saves from the form and shows validation errors in place', function () {
    Livewire::test(SettingsForm::class, ['scope' => SettingScope::Platform, 'ability' => 'settings.manage'])
        ->set('values.invitations__expiry_days', 0)
        ->call('save', 'invitations__expiry_days')
        ->assertHasErrors('values.invitations__expiry_days')
        ->set('values.invitations__expiry_days', 10)
        ->call('save', 'invitations__expiry_days')
        ->assertHasNoErrors()
        ->assertDispatched('saved');

    expect($this->settings->get('invitations.expiry_days'))->toBe(10);
});

it('blocks non-admins from saving settings', function () {
    signInAs(Role::Creator);

    Livewire::test(SettingsForm::class, ['scope' => SettingScope::Platform, 'ability' => 'settings.manage'])
        ->assertSee('your role can')
        ->call('save', 'invitations__expiry_days')
        ->assertForbidden();
});

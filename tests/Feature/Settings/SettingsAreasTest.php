<?php

use App\Auth\Role;
use App\Livewire\Settings\SettingsForm;
use App\Navigation\Navigation;
use App\Navigation\NavSection;
use App\Settings\SettingControl;
use App\Settings\SettingDefinition;
use App\Settings\SettingsAreas;
use App\Settings\SettingScope;
use App\Settings\SettingsRegistry;
use Livewire\Livewire;

/** The page without quick find's list of every page and setting. */
function pageOnly(string $html): string
{
    return preg_replace('#<script type="application/json" data-quick-find-entries>.*?</script>#s', '', $html);
}

beforeEach(function () {
    $this->admin = signInAs(Role::Admin);
    $this->brand = makeBrand(['name' => 'BioSteel']);
});

it('gives every brand setting one home: its feature, or Brand settings for the brand-wide ones', function () {
    $sections = app(SettingsRegistry::class)->settableAt(SettingScope::Brand)
        ->reject(fn (SettingDefinition $d) => $d->control === SettingControl::Custom)->pluck('section')->unique();

    // A new section lands on Brand settings only when it's added here on purpose.
    expect($sections->diff(app(SettingsAreas::class)->homedSections())->sort()->values()->all())->toBe([]);
});

it('keeps the Brand entry to the brand-wide settings, with every feature\'s entry in the list beside it', function () {
    $page = $this->get(route('brand-settings', $this->brand))->assertOk()->assertSee('data-settings-menu', false);
    expect(pageOnly($page->getContent()))->not->toContain('Model for planning and copy');

    $page->assertSee(route('area-settings', [$this->brand, 'ai']), false);
});

it('opens a feature\'s settings with a save bar, and the platform-only ones under "For every brand" for admins', function () {
    $page = $this->get(route('area-settings', [$this->brand, 'ai']))->assertOk()
        ->assertSee('AI models settings')->assertSee('Model for planning and copy')->assertSee('For every brand')->assertSee('Gemini API version')
        ->assertSee('aria-label="Save changes"', false)->assertSee('data-settings-menu', false);

    signInAs(Role::Creator);
    $this->get(route('area-settings', [$this->brand, 'ai']))->assertOk()->assertDontSee('data-for-every-brand', false)->assertDontSee('Gemini API version');
});

it('lists the entries with the open one marked', function () {
    expect(collect(app(SettingsAreas::class)->menu($this->admin, $this->brand))->map(fn ($e) => [$e['heading'], collect($e['areas'])->pluck('title')->all()])->all())->toBe([
        [null, ['AI models', 'Generation', 'Review and quality', 'Budgets and spend', 'Brand kit', 'Prompts', 'Store']],
    ]);

    $page = $this->get(route('area-settings', [$this->brand, 'ai']))->assertOk()->getContent();
    expect($page)->toMatch('#href="'.preg_quote(route('area-settings', [$this->brand, 'ai']), '#').'" wire:navigate\s+aria-current="page"#');
});

it('keeps the sidebar\'s Settings current on every entry', function () {
    $this->get(route('area-settings', [$this->brand, 'ai']))->assertOk()
        ->assertSee('href="'.route('brand-settings', $this->brand).'"', false);
    expect((collect(app(Navigation::class)->tree(NavSection::Brand, $this->admin, $this->brand))
        ->flatMap(fn ($e) => $e['items'])->firstWhere('key', 'brand-settings')->activeOn)())->toContain('area-settings');
});

it('has no settings page for an unknown area', function () {
    $this->get(route('area-settings', [$this->brand, 'nope']))->assertNotFound();
});

it('saves only the area\'s own settings', function () {
    Livewire::test(SettingsForm::class, ['scope' => SettingScope::Brand, 'scopeId' => $this->brand->id, 'ability' => 'brands.manage', 'sections' => ['AI models'], 'batch' => true])
        ->set('values.llm__effort', 'low')->call('saveAll', ['llm__effort'])->assertReturned(true)
        ->call('saveAll', ['platform__timezone'])->assertNotFound();
});

it('keeps platform Settings to what belongs to no feature', function () {
    $page = pageOnly($this->get(route('settings'))->assertOk()->getContent());

    expect($page)->toContain('Time zone')->toContain('Company email domains')->not->toContain('Model for planning and copy')->not->toContain('Gemini API version');
});

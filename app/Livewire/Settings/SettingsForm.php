<?php

namespace App\Livewire\Settings;

use App\Models\Brand;
use App\Settings\SettingControl;
use App\Settings\SettingDefinition;
use App\Settings\Settings;
use App\Settings\SettingScope;
use App\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Renders every setting that can be set at one scope, using each setting's
 * declared control. On a brand's settings, someone who can change platform
 * settings also sees each one's default for all brands, and can change it
 * there.
 */
class SettingsForm extends Component
{
    #[Locked]
    public SettingScope $scope;

    #[Locked]
    public int $scopeId = 0;

    /** Permission needed to change these settings. Anyone who can open the page can read them. A person's own settings need none: only they can change them. */
    #[Locked]
    public string $ability = '';

    /** Show only this section, when the form is embedded in another screen. */
    #[Locked]
    public ?string $section = null;

    /**
     * Show only these sections (sections), or every section but these (except).
     *
     * @var list<string>|null
     */
    #[Locked]
    public ?array $sections = null;

    /** @var list<string> */
    #[Locked]
    public array $except = [];

    /** Shows each section's name; off where the page's own heading already says it. */
    #[Locked]
    public bool $titles = true;

    /** Platform scope: only the settings a brand can't have its own of ("For every brand"). */
    #[Locked]
    public bool $platformOnly = false;

    /**
     * Brand scope: each setting's default for all brands, for those who can
     * change it, keyed like $values.
     *
     * @var array<string, mixed>
     */
    public array $platformValues = [];

    /**
     * Current values, keyed by field name (the setting key with dots swapped
     * for "__", since Livewire reads dots as nesting).
     *
     * @var array<string, mixed>
     */
    public array $values = [];

    /**
     * The values as saved, for the page's save bar to tell what changed.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $saved = [];

    /** Saved together from the page's save bar, instead of a Save button on each setting. */
    #[Locked]
    public bool $batch = false;

    public function mount(SettingScope $scope, string $ability = '', int $scopeId = 0, ?string $section = null, bool $batch = false, ?array $sections = null, array $except = [], bool $platformOnly = false, bool $titles = true): void
    {
        $this->titles = $titles;
        $this->platformOnly = $platformOnly;
        $this->batch = $batch;
        $this->sections = $sections;
        $this->except = $except;
        $this->scope = $scope;
        $this->section = $section;
        $this->scopeId = $scopeId;
        $this->ability = $ability;

        $this->loadValues();
    }

    public function save(string $field, Settings $settings): void
    {
        abort_unless($this->canEdit(), 403);

        $definition = $this->definition($field);

        try {
            $settings->set($definition->key, $this->values[$field] ?? null, $this->scope, $this->scopeId, Auth::user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                $this->addError("values.{$field}", $messages[0]);
            }

            return;
        }

        $this->resetErrorBag("values.{$field}");
        $this->loadValues();
        $this->dispatch('saved', message: "Saved \"{$definition->label}\".");
    }

    /**
     * Saves the settings changed on the page, from the save bar: all of them,
     * or none when any is invalid, so nothing is left half saved.
     *
     * @param  list<string>  $fields
     */
    public function saveAll(array $fields, Settings $settings): bool
    {
        abort_unless($this->canEdit(), 403);

        $definitions = collect($fields)->unique()->mapWithKeys(fn ($field) => [(string) $field => $this->definition((string) $field)]);
        $this->resetErrorBag();

        foreach ($definitions as $field => $definition) {
            try {
                $settings->validate($definition->key, $this->values[$field] ?? null);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $messages) {
                    $this->addError("values.{$field}", $messages[0]);
                }
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return false;
        }

        DB::transaction(fn () => $definitions->each(fn (SettingDefinition $d, string $field) => $settings->set($d->key, $this->values[$field] ?? null, $this->scope, $this->scopeId, Auth::user())));

        $this->loadValues();
        $count = $definitions->count();
        $this->dispatch('saved', message: $count === 1 ? "Saved \"{$definitions->first()->label}\"." : "Saved {$count} settings.");

        return true;
    }

    /**
     * Puts back the saved values, from the save bar.
     */
    public function discard(): void
    {
        $this->resetErrorBag();
        $this->loadValues();
    }

    public function resetToDefault(string $field, Settings $settings): void
    {
        abort_unless($this->canEdit(), 403);

        $definition = $this->definition($field);
        $settings->reset($definition->key, $this->scope, $this->scopeId, Auth::user());

        // Other settings changed on the page but not saved yet stay as they are.
        $unsaved = array_diff_key($this->values, [$field => true]);
        $this->resetErrorBag("values.{$field}");
        $this->loadValues();
        $this->values = [...$this->values, ...$unsaved];
        $this->dispatch('saved', message: "\"{$definition->label}\" is back to its default.");
    }

    /**
     * Changes a setting's default for all brands, from beside the brand's own
     * value. Values changed on the page but not saved yet stay as they are.
     */
    public function savePlatform(string $field, Settings $settings): void
    {
        $this->authorize('settings.manage');

        $definition = $this->definition($field);
        abort_unless($this->showsPlatform($definition), 404);

        try {
            $settings->set($definition->key, $this->platformValues[$field] ?? null, SettingScope::Platform, 0, Auth::user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                $this->addError("platformValues.{$field}", $messages[0]);
            }

            return;
        }

        [$before, $savedBefore] = [$this->values, $this->saved];
        $this->resetErrorBag("platformValues.{$field}");
        $this->loadValues();

        foreach ($before as $key => $value) {
            if (($savedBefore[$key] ?? null) !== $value) {
                $this->values[$key] = $value;
            }
        }
        $this->dispatch('saved', message: "Saved \"{$definition->label}\" for every brand that hasn't changed it.");
        $this->dispatch('platform-saved', field: $field);
    }

    public function render(Settings $settings, SettingsRegistry $registry)
    {
        return view('livewire.settings.settings-form', [
            'canPlatform' => $this->scope === SettingScope::Brand && Auth::user()->can('settings.manage'),
            'readOnly' => ! $this->canEdit(),
            'grouped' => $registry->settableAt($this->scope)
                ->filter(fn (SettingDefinition $d) => $this->shows($d))
                ->map(fn (SettingDefinition $d) => [
                    'field' => self::field($d->key),
                    'resolved' => $settings->resolve($d->key, ...$this->resolveArgs()),
                    'platform' => $this->showsPlatform($d) ? $settings->resolve($d->key) : null,
                ])
                ->groupBy(fn ($row) => $row['resolved']->definition->section),
        ]);
    }

    /**
     * Whether the person may change these settings: their own, or the
     * permission the page names.
     */
    private function canEdit(): bool
    {
        return $this->scope === SettingScope::User
            ? Auth::id() === $this->scopeId
            : (bool) Auth::user()?->can($this->ability, $this->brand());
    }

    /**
     * The brand being edited, for permission checks on brand settings.
     */
    private function brand(): ?Brand
    {
        return $this->scope === SettingScope::Brand ? Brand::findOrFail($this->scopeId) : null;
    }

    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    private function definition(string $field): SettingDefinition
    {
        $definition = app(SettingsRegistry::class)->get(str_replace('__', '.', $field));

        abort_unless($definition->settableAt($this->scope) && $this->shows($definition), 404);

        return $definition;
    }

    private function shows(SettingDefinition $definition): bool
    {
        return $definition->control !== SettingControl::Custom
            && ($this->section === null || $definition->section === $this->section)
            && ($this->sections === null || in_array($definition->section, $this->sections, true))
            && ! in_array($definition->section, $this->except, true)
            && (! $this->platformOnly || $definition->isPlatformOnly());
    }

    /**
     * Whether the setting's default for all brands shows beside the brand's value.
     */
    private function showsPlatform(SettingDefinition $definition): bool
    {
        return $this->scope === SettingScope::Brand && $definition->settableAt(SettingScope::Platform)
            && Auth::user()?->can('settings.manage');
    }

    private function loadValues(): void
    {
        $settings = app(Settings::class);

        $this->values = app(SettingsRegistry::class)->settableAt($this->scope)
            // Secrets never go to the browser; the field starts blank.
            ->mapWithKeys(fn (SettingDefinition $d) => [self::field($d->key) => $d->control === SettingControl::Secret ? '' : $settings->resolve($d->key, ...$this->resolveArgs())->value])
            ->all();
        $this->saved = $this->values;

        $this->platformValues = $this->scope === SettingScope::Brand && Auth::user()?->can('settings.manage')
            ? app(SettingsRegistry::class)->settableAt($this->scope)
                ->filter(fn (SettingDefinition $d) => $d->settableAt(SettingScope::Platform) && $this->shows($d))
                ->mapWithKeys(fn (SettingDefinition $d) => [self::field($d->key) => $d->control === SettingControl::Secret ? '' : $settings->resolve($d->key)->value])
                ->all()
            : [];
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveArgs(): array
    {
        return match ($this->scope) {
            SettingScope::Platform => [null, null],
            SettingScope::Brand => [$this->scopeId, null],
            SettingScope::User => [null, $this->scopeId],
        };
    }
}

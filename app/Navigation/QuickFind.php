<?php

namespace App\Navigation;

use App\Livewire\Settings\SettingsForm;
use App\Models\Brand;
use App\Models\User;
use App\Settings\SettingControl;
use App\Settings\SettingDefinition;
use App\Settings\SettingsArea;
use App\Settings\SettingsAreas;
use App\Settings\SettingScope;
use App\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Everything quick find (Ctrl+K) can jump to: the pages in the sidebar, each
 * feature's settings page, and every setting the person can see, linked to
 * the page it lives on.
 */
class QuickFind
{
    public function __construct(private Navigation $nav, private SettingsRegistry $settings, private SettingsAreas $areas) {}

    /**
     * @return list<array{title: string, where: string, url: string}>
     */
    public function entries(?User $user, ?Brand $brand): array
    {
        if (! $user) {
            return [];
        }

        $entries = [];

        foreach (NavSection::cases() as $section) {
            foreach ($this->nav->tree($section, $user, $brand) as $entry) {
                foreach ($entry['items'] as $item) {
                    $entries[] = ['title' => $item->label, 'where' => $entry['heading']?->label ?? ($section === NavSection::Brand ? $brand->name : $section->label()), 'url' => $item->url($brand)];
                }
            }
        }

        if ($brand && $user->can('brands.view', $brand)) {
            $areas = collect($this->areas->all());

            foreach ($areas->filter(fn (SettingsArea $a) => $a->visibleTo($user, $brand)) as $area) {
                $entries[] = ['title' => "{$area->title} settings", 'where' => $brand->name, 'url' => $area->url($brand)];
            }

            foreach ($this->settableAt(SettingScope::Brand) as $definition) {
                $area = $areas->first(fn (SettingsArea $a) => in_array($definition->section, $a->sections, true));
                if ($area && ! $area->visibleTo($user, $brand)) {
                    continue;
                }
                $entries[] = [
                    'title' => $definition->label,
                    'where' => 'Settings · '.($area ? $area->title : 'Brand').' · '.Str::ucfirst(Str::after($definition->section, ': ')),
                    'url' => ($area ? $area->url($brand) : route('brand-settings', $brand)).'#'.self::anchor($definition),
                ];
            }
        }

        // Platform-only settings, where they're shown: under "For every brand" in their feature's
        // entry, or on platform Settings. A brand setting's default for all brands is beside it.
        if ($user->can('settings.manage') && Route::has('settings')) {
            foreach ($this->settableAt(SettingScope::Platform)->filter(fn (SettingDefinition $d) => $d->isPlatformOnly()) as $definition) {
                $area = collect($this->areas->all())->first(fn (SettingsArea $a) => in_array($definition->section, $a->sections, true));

                if (! $area) {
                    $entries[] = ['title' => $definition->label, 'where' => "Platform settings · {$definition->section}", 'url' => route('settings').'#'.self::anchor($definition)];
                } elseif ($brand && $area->visibleTo($user, $brand)) {
                    $entries[] = ['title' => $definition->label, 'where' => "Settings · {$area->title} · For every brand", 'url' => $area->url($brand).'#'.self::anchor($definition)];
                }
            }
        }

        return $entries;
    }

    /** The id of a setting's row on a settings page. */
    public static function anchor(SettingDefinition $definition): string
    {
        return 'setting-row-'.SettingsForm::field($definition->key);
    }

    /**
     * @return iterable<SettingDefinition>
     */
    private function settableAt(SettingScope $scope): iterable
    {
        return $this->settings->settableAt($scope)->reject(fn (SettingDefinition $d) => $d->control === SettingControl::Custom);
    }
}

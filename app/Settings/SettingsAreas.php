<?php

namespace App\Settings;

use App\Models\Brand;
use App\Models\User;
use InvalidArgumentException;

/**
 * The homes of brand settings (SettingsArea), listed down the side of a
 * brand's Settings. Core features register theirs in AppServiceProvider and
 * plugins in their own providers; a plugin can also add its sections to an
 * existing area, such as "connected-services".
 */
class SettingsAreas
{
    /** The headings of the list, in order. Areas with no heading come first, after "Brand". */
    public const HEADINGS = [
        'generation' => 'Generation',
        'quality' => 'Review and quality',
        'spend' => 'Budgets and spend',
    ];

    /** @var array<string, SettingsArea> */
    private array $areas = [];

    /** @var array<string, list<string>> Sections added before their area was registered. */
    private array $waiting = [];

    public function add(SettingsArea $area): void
    {
        if (isset($this->areas[$area->key])) {
            throw new InvalidArgumentException("Settings area [{$area->key}] is already registered.");
        }

        $this->areas[$area->key] = $area->withSections($this->waiting[$area->key] ?? []);
        unset($this->waiting[$area->key]);
    }

    /**
     * @param  list<string>  $sections
     */
    public function addSections(string $key, array $sections): void
    {
        isset($this->areas[$key])
            ? $this->areas[$key] = $this->areas[$key]->withSections($sections)
            : $this->waiting[$key] = [...($this->waiting[$key] ?? []), ...$sections];
    }

    public function find(string $key): ?SettingsArea
    {
        return $this->areas[$key] ?? null;
    }

    /**
     * @return array<string, SettingsArea>
     */
    public function all(): array
    {
        return $this->areas;
    }

    /**
     * The list down the side of a brand's Settings: the areas this person can
     * open, under their headings.
     *
     * @return list<array{heading: ?string, areas: list<SettingsArea>}>
     */
    public function menu(?User $user, Brand $brand): array
    {
        $visible = collect($this->areas)->filter(fn (SettingsArea $a) => $a->visibleTo($user, $brand))->sortBy('order', SORT_NUMERIC);
        $menu = [];

        foreach ([null, ...array_keys(self::HEADINGS)] as $heading) {
            // An unknown heading lists the area at the top rather than losing it.
            $under = fn (SettingsArea $a) => isset(self::HEADINGS[$a->heading ?? '']) ? $a->heading : null;
            if (($areas = $visible->filter(fn (SettingsArea $a) => $under($a) === $heading)->values()->all()) !== []) {
                $menu[] = ['heading' => $heading === null ? null : self::HEADINGS[$heading], 'areas' => $areas];
            }
        }

        return $menu;
    }

    /**
     * The area whose Settings button shows on this page.
     */
    public function forPage(?string $routeName): ?SettingsArea
    {
        return $routeName ? collect($this->areas)->first(fn (SettingsArea $a) => $a->pageRoute === $routeName) : null;
    }

    /**
     * The pages of the areas that show their settings themselves, which are
     * entries of a brand's Settings like the rest.
     *
     * @return list<string>
     */
    public function settingsRoutes(): array
    {
        return array_values(array_filter(array_map(fn (SettingsArea $a) => $a->settingsRoute, $this->areas)));
    }

    /**
     * Sections that live with a feature rather than on Brand settings.
     *
     * @return list<string>
     */
    public function homedSections(): array
    {
        return array_values(array_unique(array_merge(...array_values(array_map(fn (SettingsArea $a) => $a->sections, $this->areas)) ?: [[]])));
    }
}

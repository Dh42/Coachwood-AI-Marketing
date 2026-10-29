<?php

namespace App\Settings;

use App\Models\Brand;
use App\Models\User;
use Closure;

/**
 * Where a feature's brand settings live, so each setting has one home and
 * the "Brand" entry of a brand's Settings keeps only the brand-wide ones.
 *
 * Every area is an entry in the list down the side of a brand's Settings,
 * under its heading (SettingsAreas::HEADINGS). With no settingsRoute the
 * sections are shown there (area-settings), and the feature's page
 * (pageRoute) gets a Settings button that opens them. A feature that shows
 * its settings itself names that route.
 */
final readonly class SettingsArea
{
    /**
     * @param  list<string>  $sections  Setting sections (SettingDefinition::$section) that live here.
     * @param  ?string  $heading  The key of the heading it's listed under (SettingsAreas::HEADINGS); null lists it at the top.
     * @param  string  $ability  Permission needed to open it. Its page checks it too.
     * @param  ?Closure(Brand): bool  $when  Whether the brand has what it's for, such as video.
     * @param  int  $order  Its place under its heading.
     * @param  ?string  $component  A Livewire component (given the brand) shown above the sections, such as an account to choose.
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $sections,
        public ?string $pageRoute = null,
        public ?string $settingsRoute = null,
        public string $description = '',
        public ?string $heading = null,
        public string $ability = 'brands.view',
        public ?Closure $when = null,
        public int $order = 100,
        public ?string $component = null,
    ) {}

    public function withSections(array $sections): self
    {
        return new self($this->key, $this->title, [...$this->sections, ...$sections], $this->pageRoute, $this->settingsRoute,
            $this->description, $this->heading, $this->ability, $this->when, $this->order, $this->component);
    }

    public function visibleTo(?User $user, Brand $brand): bool
    {
        return (! $this->when || ($this->when)($brand)) && ($user?->can($this->ability, $brand) ?? false);
    }

    /**
     * The address of these settings for a brand.
     */
    public function url(Brand $brand): string
    {
        return $this->settingsRoute
            ? route($this->settingsRoute, ['brand' => $brand])
            : route('area-settings', ['brand' => $brand, 'area' => $this->key]);
    }
}

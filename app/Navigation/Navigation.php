<?php

namespace App\Navigation;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

/**
 * Sidebar registry. Core screens register here in AppServiceProvider, and
 * core plugins add their own pages the same way, so the sidebar never needs
 * editing to gain an item.
 */
class Navigation
{
    /** @var array<string, NavItem> */
    private array $items = [];

    /** @var array<string, NavHeading> */
    private array $headings = [];

    public function addHeading(NavHeading $heading): void
    {
        if (isset($this->headings[$heading->key])) {
            throw new InvalidArgumentException("Navigation heading [{$heading->key}] is already registered.");
        }

        $this->headings[$heading->key] = $heading;
    }

    /**
     * What the sidebar draws for a section, in order: items on their own, and
     * headings with the items under them that this user can open. A heading
     * with none is left out; one with a single item becomes that item. An item
     * naming a heading that isn't registered shows on its own.
     *
     * @return Collection<int, array{heading: ?NavHeading, items: Collection<int, NavItem>}>
     */
    public function tree(NavSection $section, ?User $user, ?Brand $brand = null): Collection
    {
        $visible = $this->visibleIn($section, $user, $brand);
        $entries = [];

        foreach ($visible as $item) {
            $heading = $item->heading ? ($this->headings[$item->heading] ?? null) : null;

            if ($heading && $heading->section === $section) {
                $entries["h:{$heading->key}"] ??= ['heading' => $heading, 'items' => collect(), 'order' => $heading->order];
                $entries["h:{$heading->key}"]['items']->push($item);
            } else {
                $entries["i:{$item->key}"] = ['heading' => null, 'items' => collect([$item]), 'order' => $item->order];
            }
        }

        return collect($entries)
            ->map(fn (array $e) => $e['heading'] && $e['items']->count() === 1 ? [...$e, 'heading' => null] : $e)
            ->sortBy('order')
            ->map(fn (array $e) => ['heading' => $e['heading'], 'items' => $e['items']->values()])
            ->values();
    }

    public function add(NavItem $item): void
    {
        if (isset($this->items[$item->key])) {
            throw new InvalidArgumentException("Navigation item [{$item->key}] is already registered.");
        }

        $this->items[$item->key] = $item;
    }

    /**
     * @return Collection<int, NavItem>
     */
    public function section(NavSection $section): Collection
    {
        return collect($this->items)
            ->filter(fn (NavItem $item) => $item->section === $section)
            ->sortBy('order')
            ->values();
    }

    /**
     * The items in a section that this user is allowed to open.
     *
     * @return Collection<int, NavItem>
     */
    public function visibleIn(NavSection $section, ?User $user, ?Brand $brand = null): Collection
    {
        return $this->section($section)
            // A page whose route isn't registered (a stale route cache mid-deploy) is
            // left out rather than breaking every page that draws the sidebar.
            ->filter(fn (NavItem $item) => Route::has($item->route) && $item->visibleTo($user, $item->section === NavSection::Brand ? $brand : null))
            ->values();
    }

    /**
     * Where to send a user who lands on the home page: the first screen they
     * can open, or null if they have no access yet.
     */
    public function firstVisibleTo(?User $user, ?Brand $brand = null): ?NavItem
    {
        foreach (NavSection::cases() as $section) {
            if ($item = $this->visibleIn($section, $user, $brand)->first()) {
                return $item;
            }
        }

        return null;
    }

    public function find(string $key): ?NavItem
    {
        return $this->items[$key] ?? null;
    }
}

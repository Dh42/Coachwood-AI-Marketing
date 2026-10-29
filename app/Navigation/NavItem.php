<?php

namespace App\Navigation;

use App\Models\Brand;
use App\Models\User;
use Closure;

final readonly class NavItem
{
    /**
     * @param  ?string  $ability  Permission needed to see the item. Routes check it too;
     *                            hiding the link is never the only guard.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $route,
        public string $icon,
        public NavSection $section,
        public int $order = 100,
        public ?string $ability = null,
        /** A count shown beside the label, such as open alerts. Zero shows nothing. */
        public ?Closure $badge = null,
        /** Brand screens only: whether the brand has what the screen is for, such as video. */
        public ?Closure $when = null,
        /** The key of the NavHeading it sits under; null shows it on its own. */
        public ?string $heading = null,
        /** @var list<string>|(Closure(): list<string>)|null The routes it's the current page for, when its route's own children are other items' pages. */
        public array|Closure|null $activeOn = null,
    ) {}

    public function badgeFor(?User $user, ?Brand $brand): int
    {
        return $this->badge && $user && $brand ? (int) ($this->badge)($user, $brand) : 0;
    }

    public function isActive(): bool
    {
        $routes = $this->activeOn instanceof Closure ? ($this->activeOn)() : $this->activeOn;

        return request()->routeIs(...($routes ?? [$this->route, $this->route.'.*']));
    }

    /**
     * Brand screens are checked against the current brand, so someone with a
     * role in just that brand sees them.
     */
    public function visibleTo(?User $user, ?Brand $brand = null): bool
    {
        if ($this->section === NavSection::Brand && ($brand === null || ($this->when && ! ($this->when)($brand)))) {
            return false;
        }

        return $this->ability === null || ($user?->can($this->ability, $brand) ?? false);
    }

    public function url(?Brand $brand = null): string
    {
        return $this->section === NavSection::Brand ? route($this->route, $brand) : route($this->route);
    }
}

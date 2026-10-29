<?php

namespace App\Navigation;

/**
 * A heading in the sidebar that opens and closes, such as "Reporting". Items
 * name it with `heading:`; it shows only when the user can open at least one
 * of them, and a heading with a single item shows as that item.
 */
final readonly class NavHeading
{
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public NavSection $section,
        /** Where it sits among the section's items, by the same `order`. */
        public int $order = 100,
    ) {}
}

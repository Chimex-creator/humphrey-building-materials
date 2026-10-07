<?php

namespace App\Support;

/**
 * Maps category slugs to inline SVG icons for the storefront.
 * Categories have no icon column — this keeps icons in code, data in the DB.
 */
class CategoryIcons
{
    /** Default icon when a slug has no match. */
    public const FALLBACK = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>';

    public static function for(string $slug): string
    {
        $icons = [
            'cement-concrete' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 20h16M6 20V8l6-4 6 4v12M9 20v-6h6v6"/></svg>',
            'steel-iron' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 7h18M3 12h18M3 17h18M7 5v14M17 5v14"/></svg>',
            'timber-plywood' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 6h18v4H3zM3 14h18v4H3z"/></svg>',
            'sand-aggregates' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 18h18L14 8l-3 5-2-3z"/></svg>',
            'tiles-flooring' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8"/><rect x="13" y="3" width="8" height="8"/><rect x="3" y="13" width="8" height="8"/><rect x="13" y="13" width="8" height="8"/></svg>',
            'plumbing' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 3v6a6 6 0 0 0 12 0V3M12 15v6"/></svg>',
            'electrical' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>',
            'paint-chemicals' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="12" height="6" rx="1"/><path d="M9 9v4a2 2 0 0 0 2 2h0a2 2 0 0 1 2 2v2a2 2 0 0 0 2 2h2"/></svg>',
        ];

        return $icons[$slug] ?? self::FALLBACK;
    }
}

<?php

namespace App\Support;

/**
 * Display-only names for delivery states.
 *
 * The database keeps whatever it already stores (`delivery_states.name` and
 * the order snapshot `orders.delivery_state_name`); this map only changes
 * what people READ in the interface. Nothing here is ever written back, so
 * queries, zone/area ids, fees and validation rules keep using the stored
 * value exactly as before.
 */
class StateLabels
{
    /** Stored value => the name shown to customers and staff. */
    private const DISPLAY_NAMES = [
        'Federal Capital Territory' => 'Abuja (FCT)',
    ];

    /** The label for a stored state name (null/blank pass straight through). */
    public static function label(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return $name;
        }

        return self::DISPLAY_NAMES[$name] ?? $name;
    }

    /**
     * Stored names whose public label contains $text — lets a search box
     * typed with the public label still find the record.
     *
     * @return list<string>
     */
    public static function storedNamesMatching(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $matches = [];
        foreach (self::DISPLAY_NAMES as $stored => $label) {
            if (mb_stripos($label, $text) !== false) {
                $matches[] = $stored;
            }
        }

        return $matches;
    }

    /**
     * True when $value only differs from $stored because of a display
     * rename — i.e. saving it must never rewrite the stored row.
     */
    public static function sameValue(string $stored, string $value): bool
    {
        return $value === $stored || self::label($value) === self::label($stored);
    }
}

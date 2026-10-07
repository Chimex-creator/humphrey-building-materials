<?php

namespace App\Services;

use App\Models\DeliveryArea;
use App\Models\DeliveryState;

/**
 * FINAL SPEC §7 / §8 / §13 / §43 — turns a state/area choice into the
 * delivery fee that is actually charged, on the server, before the order
 * exists.
 *
 *  - FCT / Nasarawa (uses_zones): State → Area → active Zone → Zone fee.
 *    No area, no mapping or an inactive row resolves to "unavailable" —
 *    never a silent state-fee fallback, never a guessed price.
 *  - Other states: State-Level fee. Free text never changes the price.
 *
 * null = we do not deliver to this location; checkout shows the
 * "contact Customer Care" message instead of inventing a fee.
 */
class DeliveryPricing
{
    /**
     * @return array{fee: float, state: DeliveryState, area: ?DeliveryArea, source: string}|null
     *         null = we do not deliver to this location
     */
    public function resolve(int $stateId, ?int $areaId = null): ?array
    {
        $state = DeliveryState::with('areas')->find($stateId);

        if (! $state || ! $state->isActive()) {
            return null;
        }

        // FCT / Nasarawa — the area decides the zone; no fallbacks (§13).
        if ($state->uses_zones) {
            if ($areaId === null) {
                return null;
            }

            $area = $state->areas->firstWhere('id', $areaId);

            if (! $area || ! $area->isActive() || $area->delivery_zone_id === null) {
                return null;
            }

            $zone = $area->zone;

            if (! $zone || ! $zone->isActive()) {
                return null;
            }

            return [
                'fee' => (float) $zone->fee,
                'state' => $state,
                'area' => $area,
                'source' => 'zone',
            ];
        }

        // Other states — the configured state-level fee (§8), area ignored.
        if ($state->default_fee === null) {
            return null;
        }

        return [
            'fee' => (float) $state->default_fee,
            'state' => $state,
            'area' => null,
            'source' => 'state',
        ];
    }
}

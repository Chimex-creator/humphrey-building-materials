<?php

namespace Database\Seeders;

use App\Models\DeliveryArea;
use App\Models\DeliveryState;
use App\Models\DeliveryZone;
use Illuminate\Database\Seeder;

/**
 * FINAL SPEC §3 / §9 / §11 / §12 — authoritative delivery starter data.
 *
 *  - Zones 1–10 with exact fees and explicit numeric zone_number (§56)
 *  - All 36 states + FCT with the exact 37-entry state price table (§12)
 *  - Every supplied FCT/Nasarawa area mapped to its zone (§9)
 *
 * This seeder is authoritative for starter pricing: fees, zone numbers and
 * area→zone mappings are (re)written to the exact specification values, so
 * running it repairs drifted configuration. Status flags (active/inactive)
 * are only set when a row is first created — serviceability stays an admin
 * decision (§46).
 *
 * Duplicate area names across zones (Ado, Mararaba, Masaka...) resolve to
 * ONE active mapping: the first zone that claims the name wins, i.e. the
 * lower zone number — Mararaba/Nyanya stay in Zone 1 at ₦5,000 (§10).
 */
class DeliveryZoneSeeder extends Seeder
{
    /**
     * Zone number => [fee, areas grouped by state].
     * States listed here are the only zone-priced states (FCT + Nasarawa).
     */
    private const ZONES = [
        1 => [
            'fee' => 5000,
            'areas' => [
                'Federal Capital Territory' => [
                    'Nyanya', 'New Nyanya',
                ],
                'Nasarawa' => [
                    'Ado', 'Karu', 'New Karu',
                    'Mararaba', 'Mararaba Phase 1', 'Mararaba Phase 2', 'Mararaba Phase 3',
                    'Masaka', 'Uke Junction', 'Koroduma', 'Auta Balefi',
                ],
            ],
        ],
        2 => [
            'fee' => 7000,
            'areas' => [
                'Federal Capital Territory' => [
                    'Jikwoyi', 'Karshi', 'Kurudu', 'Orozo', 'Kugbo',
                    'Dutse Alhaji', 'Galadimawa', 'Apo', 'Lokogoma', 'Gudu',
                    'Asokoro', 'Garki',
                    'Area 1', 'Area 2', 'Area 3', 'Area 8',
                    'Wuye',
                ],
            ],
        ],
        3 => [
            'fee' => 9000,
            'areas' => [
                'Federal Capital Territory' => [
                    'Wuse', 'Wuse 2', 'Garki 1', 'Garki 2', 'Central Area',
                    'Area 10', 'Area 11', 'Area 21', 'Area 22', 'Area 23',
                    'Jabi', 'Utako', 'Mabushi', 'Kado', 'Life Camp',
                    'Gwarinpa', 'Katampe', 'Jahi',
                ],
            ],
        ],
        4 => [
            'fee' => 12000,
            'areas' => [
                'Federal Capital Territory' => [
                    'Kubwa', 'Bwari', 'Dutse', 'Dei-Dei', 'Mpape',
                    'Maitama', 'Asokoro Extension', 'Guzape', 'Dawaki',
                    'Idu', 'Karsana', 'Lugbe', 'Airport Road',
                ],
            ],
        ],
        5 => [
            'fee' => 15000,
            'areas' => [
                'Federal Capital Territory' => [
                    'Kuje', 'Kwali', 'Abaji', 'Gwagwalada',
                ],
            ],
            // Suleja, Madalla, Diko and Tafa are Niger State and must stay
            // OUT of Zones 1–10 (§9) — Niger uses its state-level price.
        ],
        6 => [
            'fee' => 7000,
            'areas' => [
                'Nasarawa' => [
                    // Ado / New Karu / Mararaba / Masaka / Auta Balefi /
                    // Koroduma already claimed by Zone 1 (§10 — one mapping).
                    'Uke', 'Gurku', 'Panda', 'Gitata',
                ],
            ],
        ],
        7 => [
            'fee' => 10000,
            'areas' => [
                'Nasarawa' => [
                    'Keffi', 'Angwan Iya',
                ],
            ],
        ],
        8 => [
            'fee' => 13000,
            'areas' => [
                'Nasarawa' => [
                    'Nasarawa Town', 'Toto', 'Udeni', 'Umaisha',
                ],
            ],
        ],
        9 => [
            'fee' => 16000,
            'areas' => [
                'Nasarawa' => [
                    'Akwanga', 'Nassarawa Eggon', 'Wamba', 'Andaha',
                ],
            ],
        ],
        10 => [
            'fee' => 20000,
            'areas' => [
                'Nasarawa' => [
                    'Lafia', 'Shabu', 'Kwandare', 'Assakio',
                    'Doma', 'Keana', 'Obi', 'Awe',
                ],
            ],
        ],
    ];

    /** Exact 37-entry state price table (§12 / §56). */
    private const STATES = [
        'Abia' => 50000,
        'Adamawa' => 50000,
        'Akwa Ibom' => 60000,
        'Anambra' => 42000,
        'Bauchi' => 35000,
        'Bayelsa' => 65000,
        'Benue' => 28000,
        'Borno' => 65000,
        'Cross River' => 65000,
        'Delta' => 42000,
        'Ebonyi' => 45000,
        'Edo' => 45000,
        'Ekiti' => 45000,
        'Enugu' => 40000,
        'Federal Capital Territory' => 10000,
        'Gombe' => 40000,
        'Imo' => 52000,
        'Jigawa' => 40000,
        'Kaduna' => 25000,
        'Kano' => 35000,
        'Katsina' => 48000,
        'Kebbi' => 58000,
        'Kogi' => 25000,
        'Kwara' => 38000,
        'Lagos' => 55000,
        'Nasarawa' => 20000,
        'Niger' => 20000,
        'Ogun' => 50000,
        'Ondo' => 42000,
        'Osun' => 45000,
        'Oyo' => 48000,
        'Plateau' => 28000,
        'Rivers' => 60000,
        'Sokoto' => 55000,
        'Taraba' => 45000,
        'Yobe' => 60000,
        'Zamfara' => 45000,
    ];

    /** States priced through State → Area → Zone only (§7 / §13). */
    private const ZONE_STATES = ['Federal Capital Territory', 'Nasarawa'];

    public function run(): void
    {
        $zones = $this->seedZones();
        $states = $this->seedStates();
        $this->seedAreas($zones, $states);
    }

    /** @return array<int, DeliveryZone> zone number => zone */
    private function seedZones(): array
    {
        $zones = [];

        foreach (self::ZONES as $number => $zone) {
            $name = 'Zone '.$number;

            $record = DeliveryZone::firstOrCreate(
                ['name' => $name],
                ['fee' => $zone['fee'], 'status' => DeliveryZone::STATUS_ACTIVE]
            );

            // Authoritative starter values (§11 numeric order, §56 exact fees).
            if ((int) $record->zone_number !== $number || (float) $record->fee !== (float) $zone['fee']) {
                $record->forceFill(['zone_number' => $number, 'fee' => $zone['fee']])->save();
            }

            $zones[$number] = $record->fresh();
        }

        return $zones;
    }

    /** @return array<string, DeliveryState> state name => state */
    private function seedStates(): array
    {
        $states = [];

        foreach (self::STATES as $name => $fee) {
            $usesZones = in_array($name, self::ZONE_STATES, true);

            $state = DeliveryState::firstOrCreate(
                ['name' => $name],
                [
                    'status' => DeliveryState::STATUS_ACTIVE,
                    'default_fee' => $fee,
                    'uses_zones' => $usesZones,
                ]
            );

            // Exact starter price (§12) + correct pricing mode (§13).
            if ((float) $state->default_fee !== (float) $fee || (bool) $state->uses_zones !== $usesZones) {
                $state->forceFill(['default_fee' => $fee, 'uses_zones' => $usesZones])->save();
            }

            $states[$name] = $state;
        }

        return $states;
    }

    /**
     * @param  array<int, DeliveryZone>  $zones
     * @param  array<string, DeliveryState>  $states
     */
    private function seedAreas(array $zones, array $states): void
    {
        // First zone to claim a State + Area name wins (§10 — one mapping).
        $claimed = [];

        foreach (self::ZONES as $number => $zone) {
            foreach ($zone['areas'] as $stateName => $areaNames) {
                $state = $states[$stateName] ?? null;
                $target = $zones[$number] ?? null;

                if (! $state || ! $target) {
                    continue;
                }

                foreach ($areaNames as $areaName) {
                    $key = $stateName.'|'.$areaName;

                    if (isset($claimed[$key])) {
                        continue;
                    }
                    $claimed[$key] = $number;

                    $area = DeliveryArea::firstOrCreate(
                        ['delivery_state_id' => $state->id, 'name' => $areaName],
                        [
                            'status' => DeliveryArea::STATUS_ACTIVE,
                            'delivery_zone_id' => $target->id,
                        ]
                    );

                    if ((int) $area->delivery_zone_id !== (int) $target->id) {
                        $area->forceFill(['delivery_zone_id' => $target->id])->save();
                    }
                }
            }
        }
    }
}

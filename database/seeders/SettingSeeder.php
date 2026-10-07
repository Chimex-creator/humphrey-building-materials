<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Official business settings (Master Scope Update §1).
     *
     * firstOrCreate so re-seeding never overwrites an admin's edit, but a
     * separate update() pass below repairs rows that were seeded earlier
     * with placeholder values.
     */
    public function run(): void
    {
        $defaults = [
            'business_name' => 'Humphrey Building Materials',
            'business_email' => 'humphreybuildingmaterials@gmail.com',
            'business_phone' => '+2348153667923',
            'business_address' => 'Eda plaza beside abacha road mararaba, nasarawa',
            'receipt_note' => 'Thank you for shopping with Humphrey Building Materials.',
            'logo_path' => null, // uploaded later by admin
            // Phase 9 — configurable low-stock threshold (§35), used by the
            // dashboard and the inventory screen.
            'low_stock_threshold' => '10',
        ];

        foreach ($defaults as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }

        // Repair rows created before the official details were confirmed.
        // An empty value or one of the old placeholders was never a real
        // admin edit, so it is safe to overwrite — anything else is left
        // alone because the owner may have typed it in from the Settings screen.
        $placeholders = [
            'info@humphreybuildingmaterials.com',
            '+234 800 000 0000',
            '+2348000000000',
            '12 Building Materials Road, Lagos, Nigeria',
            'customer@example.com',
            'info@example.com',
        ];

        foreach (['business_name', 'business_email', 'business_phone', 'business_address'] as $key) {
            $current = Setting::where('key', $key)->value('value');

            if ($current === null || $current === '' || in_array(trim((string) $current), $placeholders, true)) {
                Setting::updateOrCreate(['key' => $key], ['value' => $defaults[$key]]);
            }
        }
    }
}

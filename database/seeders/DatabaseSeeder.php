<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Humphrey Building Materials catalogue data.
        $this->call([
            CategorySeeder::class,
            ProductSeeder::class,
            RoleDemoSeeder::class, // demo users: admin, inventory, sales, customer
            SettingSeeder::class,  // default business settings
            DeliveryZoneSeeder::class, // zones / states / areas (idempotent, never overwrites)
        ]);
    }
}

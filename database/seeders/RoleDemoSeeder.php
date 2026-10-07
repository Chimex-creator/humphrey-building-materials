<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class RoleDemoSeeder extends Seeder
{
    /**
     * Create one demo user per role so we can test authorisation.
     * All demo accounts use password: "password"
     *
     * Clearly marked as DEMO data — replace/remove before real use.
     */
    public function run(): void
    {
        $users = [
            ['name' => 'Demo Admin',      'email' => 'admin@demo.test',      'role' => User::ROLE_ADMIN],
            ['name' => 'Demo Inventory',  'email' => 'inventory@demo.test',  'role' => User::ROLE_INVENTORY],
            ['name' => 'Demo Sales',      'email' => 'sales@demo.test',      'role' => User::ROLE_SALES],
        ];

        foreach ($users as $data) {
            User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'role' => $data['role'],
                    'password' => bcrypt('password'),
                    'email_verified_at' => now(), // demo only — real users verify via email
                ]
            );
        }
    }
}

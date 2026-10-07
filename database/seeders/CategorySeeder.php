<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * Seed the product categories for Humphrey Building Materials.
     */
    public function run(): void
    {
        // Clear existing categories (products are removed by cascade).
        Category::query()->delete();

        $categories = [
            [
                'name' => 'Cement & Concrete',
                'description' => 'Cement, concrete mix and related products for strong, lasting builds.',
            ],
            [
                'name' => 'Steel & Iron',
                'description' => 'Reinforcing bars, rods and structural steel for solid foundations.',
            ],
            [
                'name' => 'Timber & Plywood',
                'description' => 'Wood planks, plywood and hardwood for framing and finishing work.',
            ],
            [
                'name' => 'Sand & Aggregates',
                'description' => 'Sharp sand, gravel and crushed stone for concrete and flooring.',
            ],
            [
                'name' => 'Tiles & Flooring',
                'description' => 'Floor tiles, wall tiles and adhesives for a professional finish.',
            ],
            [
                'name' => 'Plumbing',
                'description' => 'Pipes, fittings and bathroom supplies for safe water systems.',
            ],
            [
                'name' => 'Electrical',
                'description' => 'Cables, conduits and electrical fittings for safe wiring.',
            ],
            [
                'name' => 'Paint & Chemicals',
                'description' => 'Emulsion, gloss and protective coatings for every surface.',
            ],
        ];

        foreach ($categories as $category) {
            Category::create([
                'name' => $category['name'],
                'slug' => Str::slug($category['name']),
                'description' => $category['description'],
            ]);
        }
    }
}

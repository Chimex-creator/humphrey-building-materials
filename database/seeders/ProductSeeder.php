<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * Seed sample products for Humphrey Building Materials.
     */
    public function run(): void
    {
        Product::query()->delete();

        // Helper: find a category id by name (categories are seeded first).
        $categoryId = fn (string $name): int => Category::where('name', $name)->first()->id;

        $products = [
            // Cement & Concrete
            [
                'category' => 'Cement & Concrete',
                'name' => 'Dangote Cement 42.5R',
                'description' => 'High-quality Portland cement suitable for general construction, slabs, columns and structural work. 50kg bag.',
                'price' => 9500,
                'stock_quantity' => 240,
                'unit' => 'bag',
                'is_featured' => true,
            ],
            [
                'category' => 'Cement & Concrete',
                'name' => 'BUA Cement 42.5N',
                'description' => 'Reliable all-purpose cement with strong early strength gain. Ideal for mortars and concrete. 50kg bag.',
                'price' => 9200,
                'stock_quantity' => 180,
                'unit' => 'bag',
                'is_featured' => false,
            ],
            [
                'category' => 'Cement & Concrete',
                'name' => 'Lafarge Ready-Mix Concrete',
                'description' => 'Pre-mixed concrete for driveways, foundations and floors. Available in multiple strengths.',
                'price' => 45000,
                'stock_quantity' => 0,
                'unit' => 'load',
                'is_featured' => false,
            ],

            // Steel & Iron
            [
                'category' => 'Steel & Iron',
                'name' => '12mm Reinforcing Steel Bar',
                'description' => 'High-tensile 12mm rebar for columns, beams and reinforced concrete. Standard 12m length.',
                'price' => 14000,
                'stock_quantity' => 150,
                'unit' => 'length',
                'is_featured' => true,
            ],
            [
                'category' => 'Steel & Iron',
                'name' => '16mm Reinforcing Steel Bar',
                'description' => 'Heavy-duty 16mm rebar for primary structural members and foundations. Standard 12m length.',
                'price' => 22000,
                'stock_quantity' => 95,
                'unit' => 'length',
                'is_featured' => false,
            ],
            [
                'category' => 'Steel & Iron',
                'name' => 'Binding Wire (Coil)',
                'description' => 'Galvanised binding wire for tying rebar on site. Durable and easy to twist.',
                'price' => 3500,
                'stock_quantity' => 300,
                'unit' => 'coil',
                'is_featured' => false,
            ],

            // Timber & Plywood
            [
                'category' => 'Timber & Plywood',
                'name' => '1×12 Hardwood Plank',
                'description' => 'Seasoned hardwood plank for doors, frames and general carpentry work.',
                'price' => 7500,
                'stock_quantity' => 80,
                'unit' => 'plank',
                'is_featured' => false,
            ],
            [
                'category' => 'Timber & Plywood',
                'name' => '12mm Marine Plywood',
                'description' => 'Water-resistant plywood sheet (8ft × 4ft) for roofing, furniture and wet areas.',
                'price' => 18500,
                'stock_quantity' => 60,
                'unit' => 'sheet',
                'is_featured' => true,
            ],

            // Sand & Aggregates
            [
                'category' => 'Sand & Aggregates',
                'name' => 'High-Quality Sharp Sand',
                'description' => 'Clean sharp sand for concrete, screeding and block laying. Delivered by load.',
                'price' => 28000,
                'stock_quantity' => 40,
                'unit' => 'load',
                'is_featured' => true,
            ],
            [
                'category' => 'Sand & Aggregates',
                'name' => '20mm Crushed Gravel',
                'description' => 'Well-graded gravel for concrete mixing and drainage. Bulk delivery available.',
                'price' => 32000,
                'stock_quantity' => 35,
                'unit' => 'load',
                'is_featured' => false,
            ],
            [
                'category' => 'Sand & Aggregates',
                'name' => 'Fine White Sand',
                'description' => 'Smooth fine sand for plastering, pointing and finishing coats.',
                'price' => 25000,
                'stock_quantity' => 28,
                'unit' => 'load',
                'is_featured' => false,
            ],

            // Tiles & Flooring
            [
                'category' => 'Tiles & Flooring',
                'name' => '600×600 Porcelain Floor Tile',
                'description' => 'Premium matte porcelain floor tile for living rooms, offices and lobbies. Sold per pack.',
                'price' => 4200,
                'stock_quantity' => 120,
                'unit' => 'pack',
                'is_featured' => false,
            ],
            [
                'category' => 'Tiles & Flooring',
                'name' => '300×600 Wall Tile (Glossy)',
                'description' => 'Glossy ceramic wall tile for kitchens and bathrooms. Easy to clean.',
                'price' => 3800,
                'stock_quantity' => 90,
                'unit' => 'pack',
                'is_featured' => false,
            ],
            [
                'category' => 'Tiles & Flooring',
                'name' => 'Tile Adhesive (25kg)',
                'description' => 'Flexible tile adhesive for strong, long-lasting bond on floors and walls.',
                'price' => 5500,
                'stock_quantity' => 70,
                'unit' => 'bag',
                'is_featured' => false,
            ],

            // Plumbing
            [
                'category' => 'Plumbing',
                'name' => '4” PVC Pipe (5m)',
                'description' => 'Heavy-duty PVC soil pipe for waste and drainage systems. 5 metre length.',
                'price' => 6800,
                'stock_quantity' => 110,
                'unit' => 'length',
                'is_featured' => false,
            ],
            [
                'category' => 'Plumbing',
                'name' => '½” PPR Pipe (100m coil)',
                'description' => 'Hot and cold water PPR pipe coil for residential plumbing installations.',
                'price' => 24000,
                'stock_quantity' => 45,
                'unit' => 'coil',
                'is_featured' => false,
            ],
            [
                'category' => 'Plumbing',
                'name' => 'PVC Elbow Fitting Set',
                'description' => 'Assorted PVC elbows and connectors for pipe joints. Durable and leak-free.',
                'price' => 2500,
                'stock_quantity' => 200,
                'unit' => 'set',
                'is_featured' => false,
            ],

            // Electrical
            [
                'category' => 'Electrical',
                'name' => '2.5mm² Twin & Earth Cable (100m)',
                'description' => 'Quality electrical cable for socket and lighting circuits. 100 metre roll.',
                'price' => 48000,
                'stock_quantity' => 50,
                'unit' => 'roll',
                'is_featured' => false,
            ],
            [
                'category' => 'Electrical',
                'name' => '20mm Electrical Conduit (3m)',
                'description' => 'Rigid PVC conduit for surface and concealed wiring. Lightweight and flame-retardant.',
                'price' => 1800,
                'stock_quantity' => 250,
                'unit' => 'length',
                'is_featured' => false,
            ],
            [
                'category' => 'Electrical',
                'name' => '13A Double Socket',
                'description' => 'Flush-mount double socket outlet for homes and offices. Safe and durable.',
                'price' => 4500,
                'stock_quantity' => 0,
                'unit' => 'piece',
                'is_featured' => false,
            ],

            // Paint & Chemicals
            [
                'category' => 'Paint & Chemicals',
                'name' => 'Matt Emulsion Paint (10L)',
                'description' => 'Smooth matt emulsion for interior walls. Excellent coverage and fade resistance.',
                'price' => 16500,
                'stock_quantity' => 65,
                'unit' => 'bucket',
                'is_featured' => false,
            ],
            [
                'category' => 'Paint & Chemicals',
                'name' => 'Gloss Oil Paint (4L)',
                'description' => 'High-gloss oil paint for doors, gates and metal surfaces. Weather-resistant.',
                'price' => 12000,
                'stock_quantity' => 55,
                'unit' => 'can',
                'is_featured' => false,
            ],
            [
                'category' => 'Paint & Chemicals',
                'name' => 'Tile Grout (5kg)',
                'description' => 'Cement-based grout for filling tile joints. Water-resistant and colour-fast.',
                'price' => 3200,
                'stock_quantity' => 85,
                'unit' => 'bag',
                'is_featured' => false,
            ],
        ];

        foreach ($products as $item) {
            Product::create([
                'category_id' => $categoryId($item['category']),
                'name' => $item['name'],
                'slug' => Str::slug($item['name']),
                'description' => $item['description'],
                'price' => $item['price'],
                'stock_quantity' => $item['stock_quantity'],
                'image' => null, // No image files yet — views show a styled placeholder.
                'status' => 'active',
                'unit' => $item['unit'],
                'is_featured' => $item['is_featured'],
            ]);
        }
    }
}

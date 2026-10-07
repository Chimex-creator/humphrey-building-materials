<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Public storefront: catalogue browsing, filters, product page, categories
 * and the "save for later" heart.
 */
class CatalogTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------
     | Helpers
     * ---------------------------------------------------------------- */

    private function category(array $attrs = []): Category
    {
        static $i = 0;
        $i++;

        return Category::firstOrCreate(
            ['slug' => 'category-'.$i.'-'.uniqid()],
            array_merge(['name' => 'Category '.$i], $attrs)
        );
    }

    private function product(array $attrs = []): Product
    {
        static $i = 0;
        $i++;

        return Product::create(array_merge([
            'category_id' => $this->category()->id,
            'name' => 'Catalogue Item '.$i,
            'slug' => 'catalogue-item-'.$i.'-'.uniqid(),
            'description' => 'A product used by the catalogue tests.',
            'price' => 1000,
            'stock_quantity' => 20,
            'status' => 'active',
            'unit' => 'bag',
        ], $attrs));
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER, 'is_active' => true]);
    }

    /* ------------------------------------------------------------------
     | Listing & filtering
     * ---------------------------------------------------------------- */

    public function test_only_active_products_are_listed(): void
    {
        $active = $this->product(['name' => 'Dangote Cement 42.5R']);
        $this->product(['name' => 'Hidden Product X', 'status' => 'inactive']);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee($active->name)
            ->assertDontSee('Hidden Product X');
    }

    public function test_search_narrows_the_list_by_name(): void
    {
        $this->product(['name' => 'Roofing Sheets Longspan']);
        $this->product(['name' => 'Granite Chippings']);

        $this->get(route('products.index', ['search' => 'roofing']))
            ->assertOk()
            ->assertSee('Roofing Sheets Longspan')
            ->assertDontSee('Granite Chippings');
    }

    public function test_category_filter_uses_the_slug(): void
    {
        $cement = $this->category(['name' => 'Cement']);
        $cement->slug = 'cement';
        $cement->save();

        $this->product(['name' => 'River Sand Bag', 'category_id' => $cement->id]);
        $other = $this->category(['name' => 'Steel']);
        $this->product(['name' => '12mm Reinforcing Bar', 'category_id' => $other->id]);

        $this->get(route('products.index', ['category' => 'cement']))
            ->assertOk()
            ->assertSee('River Sand Bag')
            ->assertDontSee('12mm Reinforcing Bar');

        // An unknown slug simply shows nothing instead of blowing up.
        $this->get(route('products.index', ['category' => 'does-not-exist']))
            ->assertOk()
            ->assertDontSee('River Sand Bag');
    }

    public function test_price_sorting_and_range_filters(): void
    {
        $cheap = $this->product(['name' => 'Cheap Bag', 'price' => 500]);
        $dear = $this->product(['name' => 'Dear Bag', 'price' => 9000]);

        $this->get(route('products.index', ['sort' => 'price_asc']))
            ->assertOk()
            ->assertSeeInOrder([$cheap->name, $dear->name]);

        $this->get(route('products.index', ['sort' => 'price_desc']))
            ->assertOk()
            ->assertSeeInOrder([$dear->name, $cheap->name]);

        $this->get(route('products.index', ['min_price' => 1000]))
            ->assertOk()
            ->assertSee($dear->name)
            ->assertDontSee($cheap->name);
    }

    public function test_a_non_numeric_price_filter_is_rejected(): void
    {
        $this->get(route('products.index', ['min_price' => 'not-a-price']))
            ->assertSessionHasErrors('min_price');
    }

    public function test_stock_filter_hides_sold_out_items(): void
    {
        $this->product(['name' => 'In Stock Coil', 'stock_quantity' => 12]);
        $this->product(['name' => 'Sold Out Coil', 'stock_quantity' => 0]);

        $this->get(route('products.index', ['stock' => 'in']))
            ->assertOk()
            ->assertSee('In Stock Coil')
            ->assertDontSee('Sold Out Coil');
    }

    /* ------------------------------------------------------------------
     | Product page & categories
     * ---------------------------------------------------------------- */

    public function test_a_product_page_shows_its_details(): void
    {
        $product = $this->product([
            'name' => 'ReadyMix Test Bag',
            'description' => 'Used by the catalogue suite.',
            'price' => 7800,
        ]);

        $this->get(route('products.show', $product->slug))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee($product->description);
    }

    public function test_unknown_or_inactive_products_are_404(): void
    {
        $this->get(route('products.show', 'nothing-here'))->assertNotFound();

        $inactive = $this->product(['slug' => 'inactive-item', 'status' => 'inactive']);
        $this->get(route('products.show', $inactive->slug))->assertNotFound();
    }

    public function test_the_categories_page_lists_categories(): void
    {
        $category = $this->category(['name' => 'Tiles & Adhesives']);
        $this->product(['name' => 'Porcelain Tile 60x60', 'category_id' => $category->id]);

        $this->get(route('categories.index'))
            ->assertOk()
            ->assertSee('Tiles & Adhesives');
    }

    /* ------------------------------------------------------------------
     | Removed features stay removed (Master Scope §5: no saved items,
     | no recently viewed)
     * ---------------------------------------------------------------- */

    public function test_saved_and_recently_viewed_features_do_not_exist(): void
    {
        $product = $this->product();

        $this->post('/saved-products/'.$product->id)->assertNotFound();
        $this->get('/saved-products')->assertNotFound();
        $this->assertFalse(Schema::hasTable('saved_products'));
    }
}

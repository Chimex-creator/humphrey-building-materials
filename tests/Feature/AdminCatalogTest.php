<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admin catalogue: product CRUD (with price history + audit lines),
 * the product/index filters and the category CRUD with its guard.
 */
class AdminCatalogTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------
     | Helpers
     * ---------------------------------------------------------------- */

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function category(array $attrs = []): Category
    {
        static $i = 0;
        $i++;

        return Category::firstOrCreate(
            ['slug' => 'admin-cat-'.$i.'-'.uniqid()],
            array_merge(['name' => 'Admin Category '.$i], $attrs)
        );
    }

    private function product(array $attrs = []): Product
    {
        static $i = 0;
        $i++;

        return Product::create(array_merge([
            'category_id' => $this->category()->id,
            'name' => 'Admin Item '.$i,
            'slug' => 'admin-item-'.$i.'-'.uniqid(),
            'description' => 'Used by the admin catalogue tests.',
            'price' => 5000,
            'stock_quantity' => 12,
            'status' => 'active',
            'unit' => 'bag',
        ], $attrs));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->category()->id,
            'name' => 'Fresh Product',
            'price' => 4500,
            'stock_quantity' => 25,
            'status' => 'active',
            'unit' => 'bag',
        ], $overrides);
    }

    /* ------------------------------------------------------------------
     | Access
     * ---------------------------------------------------------------- */

    public function test_the_product_admin_is_for_admin_and_inventory_only(): void
    {
        $this->get(route('admin.products.index'))->assertRedirect(route('login'));

        $sales = $this->staff(User::ROLE_SALES);
        $this->actingAs($sales)->get(route('admin.products.index'))->assertForbidden();

        $inventory = $this->staff(User::ROLE_INVENTORY);
        $this->actingAs($inventory)->get(route('admin.products.index'))->assertOk();
    }

    /* ------------------------------------------------------------------
     | Products
     * ---------------------------------------------------------------- */

    public function test_creating_a_product_records_the_initial_price(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->post(route('admin.products.store'), $this->payload(['name' => 'Ready Mix 32.5R']))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('status');

        $product = Product::where('name', 'Ready Mix 32.5R')->firstOrFail();
        $this->assertDatabaseHas('product_price_histories', [
            'product_id' => $product->id,
            'old_price' => null,
            'new_price' => 4500,
            'changed_by' => $admin->id,
            'notes' => 'Initial price',
        ]);
    }

    public function test_product_validation_rejects_bad_input(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->post(route('admin.products.store'), $this->payload(['category_id' => 999999]))
            ->assertSessionHasErrors('category_id');

        $this->actingAs($admin)
            ->post(route('admin.products.store'), $this->payload(['status' => 'sold-out']))
            ->assertSessionHasErrors('status');

        $this->product(['name' => 'Already Here']);
        $this->actingAs($admin)
            ->post(route('admin.products.store'), $this->payload(['name' => 'Already Here']))
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->post(route('admin.products.store'), $this->payload(['price' => -5]))
            ->assertSessionHasErrors('price');

        $this->assertDatabaseCount('products', 1);
    }

    public function test_changing_the_price_is_audited_but_saving_without_changes_is_not(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product(['price' => 5000]);

        $this->actingAs($admin)
            ->put(route('admin.products.update', $product), $this->payload([
                'category_id' => $product->category_id,
                'name' => $product->name,
                'price' => 6500,
            ]))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('status');

        $this->assertSame(6500.0, (float) $product->fresh()->price);
        $this->assertDatabaseHas('product_price_histories', [
            'product_id' => $product->id,
            'old_price' => 5000,
            'new_price' => 6500,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'product.price_changed',
            'subject_id' => $product->id,
        ]);

        // Saving the same numbers again writes nothing new.
        $this->actingAs($admin)
            ->put(route('admin.products.update', $product), $this->payload([
                'category_id' => $product->category_id,
                'name' => $product->name,
                'price' => 6500,
            ]))
            ->assertSessionHas('status');

        // The helper creates products straight through the model, so the only
        // price row on file is the real change — saving again adds nothing.
        $this->assertSame(1, ProductPriceHistory::where('product_id', $product->id)->count());
        $this->assertSame(1, ActivityLog::where('action', 'product.price_changed')->count());
    }

    public function test_deleting_a_product_is_audited_and_the_image_goes_with_it(): void
    {
        Storage::fake('public');
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product();

        $this->actingAs($admin)
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'product.deleted',
            'subject_id' => $product->id,
        ]);
    }

    public function test_the_product_index_filters_by_search_category_and_status(): void
    {
        $inventory = $this->staff(User::ROLE_INVENTORY);
        $cement = $this->category(['name' => 'Cement']);
        $cement->slug = 'cement-range';
        $cement->save();
        $steel = $this->category(['name' => 'Steel']);

        $this->product(['name' => 'Golden Cement 42.5R', 'category_id' => $cement->id]);
        $this->product(['name' => '16mm Rebar', 'category_id' => $steel->id]);
        $this->product(['name' => 'Retired Cement', 'category_id' => $cement->id, 'status' => 'inactive']);

        $this->actingAs($inventory)
            ->get(route('admin.products.index', ['search' => 'Golden']))
            ->assertOk()
            ->assertSee('Golden Cement 42.5R')
            ->assertDontSee('16mm Rebar');

        $this->actingAs($inventory)
            ->get(route('admin.products.index', ['category' => $cement->id]))
            ->assertOk()
            ->assertSee('Golden Cement 42.5R')
            ->assertDontSee('16mm Rebar');

        $this->actingAs($inventory)
            ->get(route('admin.products.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('Retired Cement')
            ->assertDontSee('Golden Cement 42.5R');
    }

    public function test_a_product_image_must_be_a_real_photo(): void
    {
        Storage::fake('public');
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->post(route('admin.products.store'), $this->payload([
                'image' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('image');
    }

    /* ------------------------------------------------------------------
     | Categories
     * ---------------------------------------------------------------- */

    public function test_categories_can_be_created_and_renamed(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), ['name' => 'Roofing Sheets', 'description' => 'Longspan and corrugated.'])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('status');
        $this->assertDatabaseHas('categories', ['slug' => 'roofing-sheets']);

        $category = Category::where('slug', 'roofing-sheets')->firstOrFail();
        $this->actingAs($admin)
            ->put(route('admin.categories.update', $category), ['name' => 'Roofing & Ceiling'])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('status');

        $category->refresh();
        $this->assertSame('Roofing & Ceiling', $category->name);
        $this->assertSame('roofing-ceiling', $category->slug);
    }

    public function test_a_duplicate_category_name_is_rejected(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $this->category(['name' => 'Timber']);

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), ['name' => 'Timber'])
            ->assertSessionHasErrors('name');
    }

    public function test_a_category_that_still_has_products_cannot_be_deleted(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product();

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $product->category_id))
            ->assertSessionHasErrors('category');
        $this->assertDatabaseHas('categories', ['id' => $product->category_id]);

        $empty = $this->category(['name' => 'Unused Category']);
        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $empty))
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('status');
        $this->assertDatabaseMissing('categories', ['id' => $empty->id]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 10 — deactivation enforcement reaches the storefront.
 *
 * A retired (inactive) product can never be added to the cart, and a
 * deactivated customer cannot keep shopping with a live session.
 */
class StorefrontEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER, 'is_active' => true]);
    }

    private function product(array $attrs = []): Product
    {
        static $i = 0;
        $i++;

        return Product::create(array_merge([
            'category_id' => Category::firstOrCreate(
                ['slug' => 'storefront-test'],
                ['name' => 'Storefront Test']
            )->id,
            'name' => 'Storefront Item '.$i,
            'slug' => 'storefront-item-'.$i,
            'price' => 2000,
            'stock_quantity' => 20,
            'status' => 'active',
        ], $attrs));
    }

    public function test_an_inactive_product_cannot_be_added_to_the_cart(): void
    {
        $customer = $this->customer();
        $retired = $this->product(['status' => 'inactive']);

        $this->actingAs($customer)
            ->post(route('cart.store'), [
                'product_id' => $retired->id,
                'qty' => 1,
            ])
            ->assertSessionHas('error')
            ->assertSessionMissing('status');

        // The refusal is a soft one — the shopper stays signed in.
        $this->assertAuthenticatedAs($customer);
    }

    public function test_an_active_product_can_still_be_added_to_the_cart(): void
    {
        $customer = $this->customer();
        $product = $this->product();

        $this->actingAs($customer)
            ->post(route('cart.store'), [
                'product_id' => $product->id,
                'qty' => 1,
            ])
            ->assertSessionHas('status');
    }

    public function test_the_product_page_of_a_retired_product_is_not_shown(): void
    {
        $retired = $this->product(['status' => 'inactive']);

        $this->get(route('products.show', $retired->slug))->assertNotFound();
    }

    public function test_a_deactivated_customer_cannot_keep_shopping(): void
    {
        $customer = $this->customer();
        $product = $this->product();

        $this->actingAs($customer)->get(route('products.index'))->assertOk();

        // An admin deactivates them mid-browse.
        User::whereKey($customer->id)->update(['is_active' => false]);

        $this->actingAs($customer->refresh())
            ->post(route('cart.store'), [
                'product_id' => $product->id,
                'qty' => 1,
            ])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}

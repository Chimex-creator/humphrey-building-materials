<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cart quantity +/- stepper PATCHes and gets JSON back with the
 * cart's authoritative state: line total, subtotal, counts — so the
 * page updates immediately (and can revert when the server refuses).
 * Checkout itself is covered by CartCheckoutTest / FinalCheckoutTest.
 */
class CartQtyUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function category(): Category
    {
        return Category::firstOrCreate(
            ['slug' => 'building-materials'],
            ['name' => 'Building Materials']
        );
    }

    private function product(array $attrs = []): Product
    {
        static $i = 0;
        $i++;

        return Product::create(array_merge([
            'category_id' => $this->category()->id,
            'name' => 'Qty Item '.$i,
            'slug' => 'qty-item-'.$i.'-'.uniqid(),
            'description' => 'Used by the cart quantity stepper tests.',
            'price' => 2500,
            'stock_quantity' => 10,
            'status' => 'active',
            'unit' => 'bag',
        ], $attrs));
    }

    private function withLine(Product $product, int $qty): void
    {
        $this->withSession(['cart' => [$product->id => $qty]]);
    }

    /* ------------------------------------------------------------------
     | JSON (AJAX stepper) responses
     * ---------------------------------------------------------------- */

    public function test_json_update_returns_authoritative_totals_for_instant_display(): void
    {
        $product = $this->product();
        $this->withLine($product, 2);

        $response = $this->patchJson(route('cart.update', $product->id), ['qty' => 3]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Cart updated.')
            ->assertJsonPath('qty', 3)
            ->assertJsonPath('line_total', '₦7,500')
            ->assertJsonPath('subtotal', '₦7,500')
            ->assertJsonPath('item_count', 3)
            ->assertJsonPath('cart_count', 3)
            ->assertJsonPath('removed', false);

        $this->assertSame(3, session('cart')[$product->id]);
    }

    public function test_json_update_rejects_more_than_stock_and_returns_state_to_revert_to(): void
    {
        $product = $this->product(['stock_quantity' => 10]);
        $this->withLine($product, 2);

        $response = $this->patchJson(route('cart.update', $product->id), ['qty' => 50]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('qty', 2) // unchanged — the client reverts to this
            ->assertJsonPath('subtotal', '₦5,000')
            ->assertJsonPath('cart_count', 2);

        $this->assertStringContainsString('Only 10 left', $response->getContent());
        $this->assertSame(2, session('cart')[$product->id]);
    }

    public function test_json_update_rejects_below_minimum_order_quantity(): void
    {
        $product = $this->product(['min_order_quantity' => 5, 'stock_quantity' => 100]);
        $this->withLine($product, 5);

        $response = $this->patchJson(route('cart.update', $product->id), ['qty' => 2]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('qty', 5);

        $this->assertStringContainsString('minimum order', $response->getContent());
        $this->assertSame(5, session('cart')[$product->id]);
    }

    public function test_json_update_rejects_a_quantity_that_breaks_the_step(): void
    {
        $product = $this->product(['quantity_step' => 3, 'stock_quantity' => 30]);
        $this->withLine($product, 3);

        $response = $this->patchJson(route('cart.update', $product->id), ['qty' => 4]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('qty', 3);

        $this->assertStringContainsString('multiples of 3', $response->getContent());
        $this->assertSame(3, session('cart')[$product->id]);
    }

    public function test_json_update_with_zero_removes_the_line_and_says_so(): void
    {
        $product = $this->product();
        $this->withLine($product, 4);

        $this->patchJson(route('cart.update', $product->id), ['qty' => 0])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('removed', true)
            ->assertJsonPath('qty', 0)
            ->assertJsonPath('subtotal', '₦0')
            ->assertJsonPath('item_count', 0)
            ->assertJsonPath('cart_count', 0);

        $this->assertArrayNotHasKey($product->id, session('cart', []));
    }

    public function test_json_update_validates_the_payload_shape(): void
    {
        $product = $this->product();
        $this->withLine($product, 1);

        $this->patchJson(route('cart.update', $product->id), ['qty' => 'abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['qty']);

        $this->assertSame(1, session('cart')[$product->id]);
    }

    /* ------------------------------------------------------------------
     | Normal form POST (JavaScript disabled) — unchanged behaviour
     * ---------------------------------------------------------------- */

    public function test_form_patch_still_redirects_with_a_flash_message_without_json(): void
    {
        $product = $this->product();
        $this->withLine($product, 1);

        $response = $this->patch(route('cart.update', $product->id), ['qty' => 6]);

        $response
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('status', 'Cart updated.');

        $this->assertSame(6, session('cart')[$product->id]);
    }

    public function test_form_patch_error_still_flashes_back_without_json(): void
    {
        $product = $this->product(['stock_quantity' => 10]);
        $this->withLine($product, 1);

        $response = $this->patch(route('cart.update', $product->id), ['qty' => 99]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('Only 10 left', session('error'));
        $this->assertSame(1, session('cart')[$product->id]);
    }

    public function test_the_cart_page_renders_the_plus_minus_stepper_and_js_hooks(): void
    {
        $product = $this->product();
        $this->withLine($product, 2);

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee('qty-step')
            ->assertSee('data-dir="-1"', false)
            ->assertSee('data-dir="1"', false)
            ->assertSee('js-cart-subtotal', false)
            ->assertSee('js-cart-count', false)
            ->assertSee('data-cart-line="'.$product->id.'"', false);
    }
}

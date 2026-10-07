<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Inventory, returns/close-out, sales dashboard & reports — including the
 * scope rule that no supplier, purchase, expense or profit surface exists.
 */
class InventoryReturnsReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /* ------------------------------------------------------------------
     | Helpers
     * ---------------------------------------------------------------- */

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER, 'is_active' => true]);
    }

    private function product(array $attrs = []): Product
    {
        static $i = 0;
        $i++;

        $category = Category::firstOrCreate(
            ['slug' => 'building-materials'],
            ['name' => 'Building Materials', 'description' => 'General building materials.']
        );

        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Cement 50kg '.$i,
            'slug' => 'cement-50kg-'.$i.'-'.uniqid(),
            'description' => 'Portland cement for building.',
            'price' => 4000,
            'stock_quantity' => 5,
            'status' => 'active',
            'unit' => 'bag',
            'brand' => 'Dangote',
        ], $attrs));
    }

    private function orderFor(User $customer, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'ORD-'.strtoupper(substr(uniqid(), -6)),
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '08031234567',
            'delivery_address' => '',
            'delivery_option' => 'pickup',
            'preferred_delivery_date' => now()->addDay()->toDateString(),
            'payment_option' => 'pay_on_delivery',
            'status' => 'delivered',
            'payment_status' => 'paid',
            'subtotal' => 4000,
            'delivery_fee' => 0,
            'total' => 4000,
        ], $attrs));
    }

    private function lineFor(Order $order, Product $product, int $qty = 1): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_slug' => $product->slug,
            'unit' => $product->unit,
            'unit_price' => $product->price,
            'quantity' => $qty,
            'line_total' => (float) $product->price * $qty,
        ]);
    }

    /* ------------------------------------------------------------------
     | PHASE 9 — inventory
     * ---------------------------------------------------------------- */

    public function test_inventory_is_staff_only_and_filters_low_and_out_of_stock(): void
    {
        $low = $this->product(['name' => 'Low Stock Item', 'stock_quantity' => 3]);
        $out = $this->product(['name' => 'Out Of Stock Item', 'stock_quantity' => 0]);
        $healthy = $this->product(['name' => 'Healthy Stock Item', 'stock_quantity' => 50]);

        $this->get('/admin/inventory')->assertRedirect(route('login'));
        $this->actingAs($this->customer())->get('/admin/inventory')->assertForbidden();
        $this->actingAs($this->staff(User::ROLE_SALES))->get('/admin/inventory')->assertForbidden();

        $inventory = $this->actingAs($this->staff(User::ROLE_INVENTORY));

        $inventory->get('/admin/inventory')->assertOk()
            ->assertSee($low->name)
            ->assertSee($out->name)
            ->assertSee($healthy->name);

        $inventory->get('/admin/inventory?stock=low')->assertOk()
            ->assertSee($low->name)
            ->assertSee($out->name)
            ->assertDontSee($healthy->name);

        $inventory->get('/admin/inventory?stock=out')->assertOk()
            ->assertSee($out->name)
            ->assertDontSee($healthy->name);
    }

    public function test_stock_adjustment_records_the_new_quantity_and_reason(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product(['stock_quantity' => 5]);

        $this->actingAs($admin)
            ->post(route('admin.stock-adjustments.store'), [
                'product_id' => $product->id,
                'new_quantity' => 2,
                'reason' => 'Damaged bags removed from the shop floor',
            ])
            ->assertSessionHas('status');

        $this->assertSame(2, $product->fresh()->stock_quantity);

        $adjustment = DB::table('stock_adjustments')
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($adjustment);
        $this->assertSame('Damaged bags removed from the shop floor', $adjustment->reason);
        $this->assertSame($admin->id, (int) $adjustment->adjusted_by);
        $this->assertSame(-3, (int) $adjustment->difference);
    }

    public function test_checkout_reserves_stock_and_the_cart_refuses_more_than_is_left(): void
    {
        $customer = $this->customer();
        $product = $this->product(['stock_quantity' => 5]);

        // Checkout reaches Paystack — fake it so the test never hits the real API.
        Http::fake(['*' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/fake-token',
                'access_code' => 'fake',
                'reference' => 'fake',
            ],
        ])]);

        // Cart refuses more than the shelf holds.
        $this->actingAs($customer)
            ->post(route('cart.store'), ['product_id' => $product->id, 'qty' => 10])
            ->assertSessionHas('error');
        $this->assertSame(5, $product->fresh()->stock_quantity);

        // 3 in the cart, then checkout → stock is reserved immediately.
        $this->actingAs($customer)
            ->post(route('cart.store'), ['product_id' => $product->id, 'qty' => 3])
            ->assertSessionHas('status');

        $this->actingAs($customer)
            ->post(route('checkout.store'), [
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => '08031234567',
                'delivery_option' => 'pickup',
                'payment_option' => 'paystack',
            ])
            ->assertRedirect();

        $this->assertSame(2, $product->fresh()->stock_quantity);
        $this->assertSame(1, Order::count());
        $this->assertSame([], session('cart', []));
    }

    /* ------------------------------------------------------------------
     | PHASE 10 — returns, inspection, stock restore, close-out
     * ---------------------------------------------------------------- */

    public function test_return_request_review_receive_inspect_complete_and_close_out(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();
        $product = $this->product(['stock_quantity' => 4]);
        $order = $this->orderFor($customer);
        $this->lineFor($order, $product, 1);

        Payment::create([
            'order_id' => $order->id,
            'amount' => 4000,
            'method' => 'paystack',
            'status' => 'paid',
            'reference' => 'RCPT-RET-0001',
            'paid_at' => now(),
        ]);

        // 1. The customer asks (Final Spec §27 — no money comes back).
        $this->actingAs($customer)
            ->post(route('orders.return.store', $order), [
                'product_id' => $product->id,
                'quantity' => 1,
                'reason' => 'Bag arrived torn.',
            ])
            ->assertSessionHas('status');

        $return = ProductReturn::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('pending', $return->status);

        // 2. Staff start the review (§28).
        $this->actingAs($admin)
            ->post(route('admin.returns.review', $return))
            ->assertSessionHas('status');
        $this->assertSame('in_review', $return->fresh()->status);

        // 3. Approve the request.
        $this->actingAs($admin)
            ->post(route('admin.returns.approve', $return))
            ->assertSessionHas('status');
        $this->assertSame('approved', $return->fresh()->status);

        // 4. The goods arrive at the shop.
        $this->actingAs($admin)
            ->post(route('admin.returns.receive', $return))
            ->assertSessionHas('status');
        $this->assertSame('received', $return->fresh()->status);

        // 5. Inspection must split the quantity exactly (§28).
        $this->actingAs($admin)
            ->post(route('admin.returns.inspect', $return), [
                'resellable_quantity' => 1,
                'damaged_quantity' => 0,
                'inspection_note' => 'Repackaged — still saleable.',
            ])
            ->assertSessionHas('status');
        $this->assertSame('inspected', $return->fresh()->status);
        $this->assertSame(1, (int) $return->fresh()->resellable_quantity);
        $this->assertSame(0, (int) $return->fresh()->damaged_quantity);

        // Nothing touches stock until the return is completed.
        $this->assertSame(4, $product->fresh()->stock_quantity);

        // 6. Complete: only resellable units go back on the shelf.
        $this->actingAs($admin)
            ->post(route('admin.returns.complete', $return))
            ->assertSessionHas('status');
        $this->assertSame('completed', $return->fresh()->status);
        $this->assertNotNull($return->fresh()->restocked_at);
        $this->assertSame(5, $product->fresh()->stock_quantity);

        // 7. No refund fields exist anywhere on the record (§27).
        $this->assertArrayNotHasKey('refund_status', $return->fresh()->getAttributes());
        $this->assertArrayNotHasKey('refund_amount', $return->fresh()->getAttributes());

        // 8. Now the order can be closed out.
        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order), ['status' => 'closed'])
            ->assertSessionHas('status');
        $this->assertSame('closed', $order->fresh()->status);
    }

    public function test_inspection_must_add_up_to_the_returned_quantity(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();
        $product = $this->product(['stock_quantity' => 4]);
        $order = $this->orderFor($customer);
        $this->lineFor($order, $product, 2);

        $this->actingAs($customer)
            ->post(route('orders.return.store', $order), [
                'product_id' => $product->id,
                'quantity' => 2,
                'reason' => 'Two bags burst open.',
            ]);

        $return = ProductReturn::where('order_id', $order->id)->firstOrFail();

        $this->actingAs($admin)->post(route('admin.returns.review', $return));
        $this->actingAs($admin)->post(route('admin.returns.approve', $return));
        $this->actingAs($admin)->post(route('admin.returns.receive', $return));

        // 1 + 0 ≠ 2 → refused, stock untouched.
        $this->actingAs($admin)
            ->post(route('admin.returns.inspect', $return), [
                'resellable_quantity' => 1,
                'damaged_quantity' => 0,
            ])
            ->assertSessionHasErrors('resellable_quantity');

        $this->assertSame('received', $return->fresh()->status);
        $this->assertSame(4, $product->fresh()->stock_quantity);
    }

    public function test_an_order_cannot_be_closed_while_a_return_is_still_open(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();
        $product = $this->product();
        $order = $this->orderFor($customer);
        $this->lineFor($order, $product, 1);

        $this->actingAs($customer)
            ->post(route('orders.return.store', $order), [
                'product_id' => $product->id,
                'quantity' => 1,
                'reason' => 'Wrong item delivered.',
            ]);

        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order), ['status' => 'closed'])
            ->assertSessionHas('error');

        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_returns_area_is_reachable_by_the_roles_that_own_stock_and_money(): void
    {
        // Guests are bounced to login, customers are refused outright.
        $this->get(route('admin.returns.index'))->assertRedirect(route('login'));
        $this->actingAs($this->customer())->get(route('admin.returns.index'))->assertForbidden();

        // Returns touch both stock (inventory) and money (sales), so both
        // staff roles are deliberately allowed in — as documented in routes/web.php.
        $this->actingAs($this->staff(User::ROLE_SALES))->get(route('admin.returns.index'))->assertOk();

        $inventory = $this->actingAs($this->staff(User::ROLE_INVENTORY));
        $inventory->get(route('admin.returns.index'))->assertOk();
        $inventory->get(route('admin.returns.create'))->assertOk();
    }

    /* ------------------------------------------------------------------
     | PHASE 11 — sales dashboard & reports
     * ---------------------------------------------------------------- */

    public function test_reports_show_revenue_unpaid_orders_and_top_products(): void
    {
        $customer = $this->customer();
        $product = $this->product(['name' => 'Roofing Sheet Product']);
        $order = $this->orderFor($customer, ['status' => 'delivered']);
        $this->lineFor($order, $product, 1);

        Payment::create([
            'order_id' => $order->id,
            'amount' => 4000,
            'method' => 'pay_on_delivery',
            'status' => 'paid',
            'reference' => 'RCPT-RPT-0001',
            'paid_at' => now(),
        ]);

        // Guests first — actingAs() sticks for the rest of the method.
        $this->get(route('admin.reports.index'))->assertRedirect(route('login'));

        $this->actingAs($this->staff(User::ROLE_INVENTORY))
            ->get(route('admin.reports.index'))
            ->assertForbidden();

        $this->actingAs($this->staff(User::ROLE_SALES))
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Orders Awaiting Payment')
            ->assertSee($product->name);
    }

    public function test_reports_can_be_exported_as_csv(): void
    {
        $this->actingAs($this->staff(User::ROLE_ADMIN))
            ->get(route('admin.reports.export'))
            ->assertOk()
            ->assertDownload();
    }

    public function test_dashboard_renders_for_staff_and_blocks_guests(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->actingAs($this->customer())->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($this->staff(User::ROLE_SALES))->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($this->staff(User::ROLE_ADMIN))->get(route('admin.dashboard'))->assertOk();
    }

    /* ------------------------------------------------------------------
     | Scope rule — suppliers, purchases, expenses and profit stay out
     * ---------------------------------------------------------------- */

    public function test_no_supplier_purchase_expense_or_profit_surface_exists(): void
    {
        $forbidden = ['supplier', 'purchase', 'expense', 'profit', 'cost_of_goods'];

        foreach (Route::getRoutes() as $route) {
            $haystack = strtolower($route->getName().' '.$route->uri());

            foreach ($forbidden as $word) {
                $this->assertStringNotContainsString(
                    $word,
                    $haystack,
                    'Unexpected route found: '.$route->getName().' ('.$route->uri.')'
                );
            }
        }

        foreach (['suppliers', 'purchases', 'expenses'] as $view) {
            $this->assertFileDoesNotExist(resource_path('views/admin/'.$view.'.blade.php'));
            $this->assertDirectoryDoesNotExist(resource_path('views/admin/'.$view));
        }

        $migrationNames = collect(glob(database_path('migrations/*.php')))->implode("\n");
        foreach ($forbidden as $word) {
            $this->assertStringNotContainsString($word, $migrationNames);
        }
    }
}

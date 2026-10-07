<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Audit & activity logs (Master §47): who did what, to which record, when.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_fake_secret_key';

    private const BASE = 'https://api.paystack.co';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config([
            'services.paystack.key' => self::SECRET,
            'services.paystack.public_key' => 'pk_test_fake_public_key',
            'services.paystack.url' => self::BASE,
        ]);
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
            'name' => 'Audit Item '.$i,
            'slug' => 'audit-item-'.$i.'-'.uniqid(),
            'description' => 'For the audit trail tests.',
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
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 4000,
            'delivery_fee' => 0,
            'total' => 4000,
        ], $attrs));
    }

    private function logFor(string $action): ?ActivityLog
    {
        return ActivityLog::where('action', $action)->latest('id')->first();
    }

    /* ------------------------------------------------------------------
     | The listed sensitive actions (§47)
     * ---------------------------------------------------------------- */

    public function test_stock_adjustment_writes_an_audit_line(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product(['stock_quantity' => 5]);

        $this->actingAs($admin)
            ->post(route('admin.stock-adjustments.store'), [
                'product_id' => $product->id,
                'new_quantity' => 2,
                'reason' => 'Two bags torn in transit',
            ]);

        $log = $this->logFor('stock.adjusted');

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(Product::class, $log->subject_type);
        $this->assertSame($product->id, $log->subject_id);
        $this->assertSame($product->name, $log->subject_label);
        $this->assertStringContainsString('from 5 to 2', $log->description);
        $this->assertSame('Two bags torn in transit', $log->details['reason']);
        $this->assertSame(-3, (int) $log->details['difference']);
        $this->assertNotNull($log->ip_address);
    }

    public function test_price_change_is_audited_but_an_unchanged_price_is_not(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product(['price' => 4000]);

        $payload = [
            'category_id' => $product->category_id,
            'name' => $product->name,
            'description' => $product->description,
            'price' => 4500,
            'stock_quantity' => 5,
            'unit' => 'bag',
            'brand' => 'Dangote',
            'status' => 'active',
        ];

        $this->actingAs($admin)->put(route('admin.products.update', $product), $payload);

        $log = $this->logFor('product.price_changed');
        $this->assertNotNull($log);
        $this->assertSame('4000', (string) (int) $log->details['old_price']);
        $this->assertSame('4500', (string) (int) $log->details['new_price']);

        // A save with the same price must not create noise.
        $before = ActivityLog::where('action', 'product.price_changed')->count();
        $payload['price'] = 4500;

        $this->actingAs($admin)->put(route('admin.products.update', $product), $payload);

        $this->assertSame($before, ActivityLog::where('action', 'product.price_changed')->count());
    }

    public function test_product_deletion_is_audited_and_survives_the_record(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product(['price' => 7250, 'stock_quantity' => 9]);

        $this->actingAs($admin)->delete(route('admin.products.destroy', $product));

        $log = $this->logFor('product.deleted');

        $this->assertNotNull($log);
        $this->assertNull(Product::find($product->id));
        $this->assertSame($product->name, $log->subject_label);
        $this->assertStringContainsString('7,250', $log->description);
        $this->assertSame(9, (int) $log->details['stock_quantity']);
    }

    public function test_recording_a_payment_by_hand_is_gone(): void
    {
        // Final Spec §18/§27: money only ever moves through Paystack —
        // there is no manual "record payment" action left to audit.
        $admin = $this->staff(User::ROLE_ADMIN);
        $order = $this->orderFor($this->customer());

        $this->actingAs($admin)
            ->post('/admin/orders/'.$order->id.'/record-payment', [
                'amount' => 4000,
                'method' => 'pay_on_delivery',
                'notes' => 'Cash at the counter',
            ])
            ->assertNotFound();

        $this->assertNull($this->logFor('payment.recorded'));
        $this->assertSame(0, Payment::count());
    }

    public function test_cancelling_an_order_is_audited(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $order = $this->orderFor($this->customer());

        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order), ['status' => 'cancelled']);

        $log = $this->logFor('order.cancelled');

        $this->assertNotNull($log);
        $this->assertSame($order->order_number, $log->subject_label);
        $this->assertSame('pending', $log->details['from_status']);
        $this->assertStringContainsString('was cancelled while "Awaiting Payment"', $log->description);

        // Moving a non-cancelled status must not write an "order.cancelled" line.
        ActivityLog::where('action', 'order.cancelled')->delete();
        $other = $this->orderFor($this->customer(), ['status' => 'confirmed']);

        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $other), ['status' => 'ready_for_pickup']);

        $this->assertNull($this->logFor('order.cancelled'));
    }

    public function test_verifying_a_paystack_payment_is_audited_with_the_acting_user(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $order = $this->orderFor($this->customer());
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => 4000,
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'HBMAUDIT1234567',
        ]);

        Http::fake([
            self::BASE.'/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => 'HBMAUDIT1234567',
                    'amount' => 400000,
                    'currency' => 'NGN',
                    'id' => 555,
                    'channel' => 'card',
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.payments.verify', $payment));

        $verified = $this->logFor('payment.verified');
        $received = $this->logFor('payment.paid');

        $this->assertNotNull($verified);
        $this->assertSame($admin->id, $verified->user_id);
        $this->assertSame('paid', $verified->details['result']);

        $this->assertNotNull($received);
        $this->assertSame($admin->id, $received->user_id);
        $this->assertSame('HBMAUDIT1234567', $received->details['reference']);
    }

    public function test_a_webhook_payment_is_audited_without_a_logged_in_user(): void
    {
        $order = $this->orderFor($this->customer());
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => 4000,
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'HBMWHK123456789',
        ]);

        Http::fake([
            self::BASE.'/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => 'HBMWHK123456789',
                    'amount' => 400000,
                    'currency' => 'NGN',
                    'id' => 777,
                    'channel' => 'card',
                ],
            ]),
        ]);

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => $payment->reference],
        ]);

        $this->call('POST', route('paystack.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, self::SECRET),
        ], $payload)->assertOk();

        $log = $this->logFor('payment.paid');

        $this->assertNotNull($log);
        $this->assertNull($log->user_id, 'A webhook has no session, so the entry belongs to the system.');
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_staff_creation_role_change_and_deactivation_are_audited(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Joy Musa',
            'email' => 'joy.musa@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_SALES,
        ]);

        $created = $this->logFor('user.created');
        $this->assertNotNull($created);
        $this->assertSame('Joy Musa', $created->subject_label);
        $this->assertSame(User::ROLE_SALES, $created->details['role']);

        $joy = User::where('email', 'joy.musa@example.com')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.users.update', $joy), [
            'name' => $joy->name,
            'email' => $joy->email,
            'role' => User::ROLE_INVENTORY,
            'phone' => '08031234567',
        ]);

        $roleLog = $this->logFor('user.role_changed');
        $this->assertNotNull($roleLog);
        $this->assertSame('sales', $roleLog->details['from_role']);
        $this->assertSame('inventory', $roleLog->details['to_role']);

        // Deactivate: is_active simply not sent.
        $this->actingAs($admin)->put(route('admin.users.update', $joy), [
            'name' => $joy->name,
            'email' => $joy->email,
            'role' => User::ROLE_INVENTORY,
            'phone' => '08031234567',
        ]);

        $this->assertNotNull($this->logFor('user.deactivated'));
        $this->assertFalse($joy->fresh()->is_active);
    }

    public function test_settings_changes_are_audited_with_only_what_changed(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        Setting::set('business_name', 'Humphrey Building Materials');
        Setting::set('business_email', 'info@humphrey.com');
        Setting::set('low_stock_threshold', '10');

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'business_name' => 'Humphrey Building Materials',
            'business_email' => 'info@humphrey.com',
            'business_phone' => '08031234567',
            'business_address' => '12 Ogui Road, Enugu',
            'receipt_note' => '',
            'low_stock_threshold' => 15,
        ]);

        $log = $this->logFor('settings.updated');

        $this->assertNotNull($log);
        $this->assertNull($log->subject_type, 'Settings are global, not a single record.');
        $this->assertArrayHasKey('low_stock_threshold', $log->details);
        $this->assertSame('10', $log->details['low_stock_threshold']['from']);
        $this->assertSame('15', $log->details['low_stock_threshold']['to']);
        $this->assertArrayHasKey('business_phone', $log->details);
        // Untouched values must not appear.
        $this->assertArrayNotHasKey('business_name', $log->details);
    }

    /* ------------------------------------------------------------------
     | The screen itself
     * ---------------------------------------------------------------- */

    public function test_the_activity_log_screen_is_admin_only_and_filterable(): void
    {
        // Guest first — actingAs() sticks for the rest of the method.
        $this->get(route('admin.activity-logs.index'))->assertRedirect(route('login'));

        $adminUser = $this->staff(User::ROLE_ADMIN);
        $product = $this->product();

        $this->actingAs($adminUser)
            ->post(route('admin.stock-adjustments.store'), [
                'product_id' => $product->id,
                'new_quantity' => 1,
                'reason' => 'Stocktake count',
            ]);

        // Customers and non-admin staff are kept out.
        $this->actingAs($this->customer())->get(route('admin.activity-logs.index'))->assertForbidden();
        $this->actingAs($this->staff(User::ROLE_SALES))->get(route('admin.activity-logs.index'))->assertForbidden();
        $this->actingAs($this->staff(User::ROLE_INVENTORY))->get(route('admin.activity-logs.index'))->assertForbidden();

        $this->actingAs($adminUser)->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Activity Log')
            ->assertSee($product->name)
            ->assertSee('Stock Adjusted');

        // Action filter
        $this->actingAs($adminUser)->get(route('admin.activity-logs.index', ['action' => 'stock.adjusted']))
            ->assertOk()
            ->assertSee('Stock Adjusted');

        $this->actingAs($adminUser)->get(route('admin.activity-logs.index', ['action' => 'order.cancelled']))
            ->assertOk()
            ->assertDontSee($product->name);

        // Free-text search (what happened, which record, or who did it)
        $this->actingAs($adminUser)->get(route('admin.activity-logs.index', ['search' => $product->name]))
            ->assertOk()
            ->assertSee($product->name);

        $this->actingAs($adminUser)->get(route('admin.activity-logs.index', ['search' => $adminUser->name]))
            ->assertOk()
            ->assertSee($product->name);

        $this->actingAs($adminUser)->get(route('admin.activity-logs.index', ['search' => 'nothing-matches-this']))
            ->assertOk()
            ->assertSee('No activity recorded yet');
    }

    public function test_every_action_the_app_can_write_has_a_label(): void
    {
        foreach (ActivityLog::ACTIONS as $action => $label) {
            $this->assertNotSame('', trim($label));
            $this->assertStringContainsString('.', $action);
        }
    }
}

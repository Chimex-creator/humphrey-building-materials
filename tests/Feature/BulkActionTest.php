<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Final Spec §39 — bulk actions: customers activate/deactivate (admin only),
 * products activate/deactivate (admin + inventory) and notification
 * mark-selected-as-read (owner only). All audited, all honest counts.
 */
class BulkActionTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------
     | Helpers
     * ---------------------------------------------------------------- */

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function customer(array $attrs = []): User
    {
        return User::factory()->create(array_merge(
            ['role' => User::ROLE_CUSTOMER, 'is_active' => true],
            $attrs
        ));
    }

    private function product(array $attrs = []): Product
    {
        static $i = 0;
        $i++;

        $category = Category::firstOrCreate(
            ['slug' => 'bulk-cat-'.$i.'-'.uniqid()],
            ['name' => 'Bulk Category '.$i]
        );

        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Bulk Item '.$i,
            'slug' => 'bulk-item-'.$i.'-'.uniqid(),
            'description' => 'Used by the bulk action tests.',
            'price' => 5000,
            'stock_quantity' => 10,
            'status' => 'active',
            'unit' => 'bag',
        ], $attrs));
    }

    private function notificationFor(User $user, string $title = 'Bulk notice')
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\OrderStatusChanged',
            'data' => ['title' => $title, 'message' => 'Body for '.$title],
        ]);
    }

    /* ------------------------------------------------------------------
     | Customers bulk (admin only)
     * ---------------------------------------------------------------- */

    public function test_guest_cannot_use_customer_bulk(): void
    {
        $this->post(route('admin.customers.bulk'), [
            'action' => 'deactivate',
            'customer_ids' => [1],
        ])->assertRedirect(route('login'));
    }

    public function test_sales_staff_cannot_use_customer_bulk(): void
    {
        $this->actingAs($this->staff(User::ROLE_SALES))
            ->post(route('admin.customers.bulk'), [
                'action' => 'deactivate',
                'customer_ids' => [1],
            ])
            ->assertForbidden();
    }

    public function test_admin_can_bulk_deactivate_customers_with_audit_line(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $one = $this->customer();
        $two = $this->customer();

        $this->actingAs($admin)->post(route('admin.customers.bulk'), [
            'action' => 'deactivate',
            'customer_ids' => [$one->id, $two->id],
        ])->assertSessionHas('status', '2 customer account(s) deactivated.');

        $this->assertFalse($one->fresh()->is_active);
        $this->assertFalse($two->fresh()->is_active);

        $log = ActivityLog::where('action', 'customers.bulk_deactivate')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(2, $log->details['count']);
    }

    public function test_bulk_deactivate_ignores_staff_ids_and_counts_honestly(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $active = $this->customer();
        $alreadyOff = $this->customer(['is_active' => false]);
        $staffId = $this->staff(User::ROLE_INVENTORY);

        $this->actingAs($admin)->post(route('admin.customers.bulk'), [
            'action' => 'deactivate',
            'customer_ids' => [$active->id, $alreadyOff->id, $staffId->id],
        ])->assertSessionHas('status', '1 customer account(s) deactivated.');

        $this->assertTrue($staffId->fresh()->is_active, 'Staff accounts must never be touched by the customer bulk action.');
    }

    public function test_bulk_activate_flips_deactivated_customers_back_on(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer(['is_active' => false]);

        $this->actingAs($admin)->post(route('admin.customers.bulk'), [
            'action' => 'activate',
            'customer_ids' => [$customer->id],
        ])->assertSessionHas('status', '1 customer account(s) activated.');

        $this->assertTrue($customer->fresh()->is_active);
        $this->assertNotNull(ActivityLog::where('action', 'customers.bulk_activate')->first());
    }

    public function test_bulk_with_nothing_to_change_says_so_honestly(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer(['is_active' => false]);

        $this->actingAs($admin)->post(route('admin.customers.bulk'), [
            'action' => 'deactivate',
            'customer_ids' => [$customer->id],
        ])->assertSessionHas('status', 'Nothing to change — the selected accounts were already in that state.');

        $this->assertNull(ActivityLog::where('action', 'customers.bulk_deactivate')->first());
    }

    public function test_bulk_customers_validates_its_inputs(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)->post(route('admin.customers.bulk'), [
            'action' => 'deactivate',
        ])->assertSessionHasErrors('customer_ids');

        $this->actingAs($admin)->post(route('admin.customers.bulk'), [
            'action' => 'delete-forever',
            'customer_ids' => [1],
        ])->assertSessionHasErrors('action');
    }

    /* ------------------------------------------------------------------
     | Products bulk (admin + inventory)
     * ---------------------------------------------------------------- */

    public function test_sales_staff_cannot_use_product_bulk(): void
    {
        $this->actingAs($this->staff(User::ROLE_SALES))
            ->post(route('admin.products.bulk'), [
                'action' => 'deactivate',
                'product_ids' => [1],
            ])
            ->assertForbidden();
    }

    public function test_inventory_staff_can_use_product_bulk(): void
    {
        $inventory = $this->staff(User::ROLE_INVENTORY);
        $product = $this->product();

        $this->actingAs($inventory)->post(route('admin.products.bulk'), [
            'action' => 'deactivate',
            'product_ids' => [$product->id],
        ])->assertSessionHas('status', '1 product(s) marked as inactive.');

        $this->assertSame('inactive', $product->fresh()->status);
        $this->assertNotNull(ActivityLog::where('action', 'products.bulk_deactivate')->first());
    }

    public function test_admin_can_bulk_activate_products(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product(['status' => 'inactive']);

        $this->actingAs($admin)->post(route('admin.products.bulk'), [
            'action' => 'activate',
            'product_ids' => [$product->id],
        ])->assertSessionHas('status', '1 product(s) marked as active.');

        $this->assertSame('active', $product->fresh()->status);
        $this->assertNotNull(ActivityLog::where('action', 'products.bulk_activate')->first());
    }

    public function test_product_bulk_validates_its_inputs(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)->post(route('admin.products.bulk'), [
            'action' => 'activate',
        ])->assertSessionHasErrors('product_ids');

        $this->actingAs($admin)->post(route('admin.products.bulk'), [
            'action' => 'activate',
            'product_ids' => [999999],
        ])->assertSessionHasErrors('product_ids.0');
    }

    /* ------------------------------------------------------------------
     | Notifications — mark selected as read (owner only)
     * ---------------------------------------------------------------- */

    public function test_mark_selected_as_read_only_touches_own_notifications(): void
    {
        $me = $this->customer();
        $stranger = $this->customer();

        $mineA = $this->notificationFor($me, 'Mine A');
        $mineB = $this->notificationFor($me, 'Mine B');
        $foreign = $this->notificationFor($stranger, 'Foreign');

        $this->actingAs($me)->post(route('notifications.read-selected'), [
            'notification_ids' => [$mineA->id, $mineB->id, $foreign->id],
        ])->assertSessionHas('status', '2 notification(s) marked as read.');

        $this->assertNotNull($mineA->fresh()->read_at);
        $this->assertNotNull($mineB->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at, 'Another user’s notification must stay unread.');
    }

    public function test_mark_selected_as_read_reports_already_read_honestly(): void
    {
        $me = $this->customer();
        $read = $this->notificationFor($me);
        $read->markAsRead();

        $this->actingAs($me)->post(route('notifications.read-selected'), [
            'notification_ids' => [$read->id],
        ])->assertSessionHas('status', 'Those notifications were already read.');
    }

    public function test_mark_selected_as_read_requires_a_selection(): void
    {
        $me = $this->customer();

        $this->actingAs($me)->post(route('notifications.read-selected'), [])
            ->assertSessionHasErrors('notification_ids');
    }

    public function test_guest_cannot_mark_notifications_read(): void
    {
        $this->post(route('notifications.read-selected'), [
            'notification_ids' => ['some-id'],
        ])->assertRedirect(route('login'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\OrderStatusChanged;
use App\Notifications\ReturnStatusUpdated;
use App\Notifications\SupportTicketUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * PHASES 11–16 — bulk actions with the same guards as the single forms.
 *
 * Orders, deliveries, returns, support tickets and categories all gain a
 * bulk path, but none of them may bypass the per-record rules: the order
 * state machine still refuses illegal jumps and open returns, "delivered"
 * still needs a received-by name, returns still only move when eligible,
 * and categories still refuse to delete anything that has products.
 */
class BulkOrdersDeliveriesReturnsSupportTest extends TestCase
{
    use RefreshDatabase;

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

    private function orderFor(User $customer, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'ORD-'.strtoupper(substr(uniqid(), -6)),
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '08031234567',
            'delivery_address' => '12 Ogui Road, Enugu',
            'delivery_option' => 'delivery',
            'preferred_delivery_date' => now()->addDay()->toDateString(),
            'payment_option' => 'paystack',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 4000,
            'delivery_fee' => 0,
            'total' => 4000,
        ], $attrs));
    }

    private function product(array $attrs = []): Product
    {
        static $i = 0;
        $i++;

        return Product::create(array_merge([
            'category_id' => Category::firstOrCreate(
                ['slug' => 'bulk-cat-'.$i],
                ['name' => 'Bulk Category '.$i]
            )->id,
            'name' => 'Bulk Product '.$i,
            'slug' => 'bulk-product-'.$i,
            'price' => 1000,
            'stock_quantity' => 50,
            'status' => 'active',
        ], $attrs));
    }

    private function ticket(User $customer, array $attrs = []): SupportTicket
    {
        static $i = 0;
        $i++;

        return SupportTicket::create(array_merge([
            'reference' => 'SUP-'.strtoupper(substr(uniqid(), -6)).'-'.$i,
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'category' => 'general_enquiry',
            'subject' => 'Question number '.$i,
            'description' => 'Something the customer needs help with.',
            'status' => 'open',
        ], $attrs));
    }

    /* ================================================================
     | PHASE 12 — orders
     * ============================================================= */

    public function test_guests_cannot_use_the_order_bulk_route(): void
    {
        $this->patch(route('admin.orders.bulk'), [
            'status' => 'cancelled',
            'order_ids' => [1],
        ])->assertRedirect(route('login'));
    }

    public function test_inventory_staff_cannot_use_the_order_bulk_route(): void
    {
        $inventory = $this->staff(User::ROLE_INVENTORY);

        $this->actingAs($inventory)
            ->patch(route('admin.orders.bulk'), [
                'status' => 'cancelled',
                'order_ids' => [1],
            ])
            ->assertForbidden();
    }

    public function test_bulk_cancel_moves_every_eligible_order_and_is_audited(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();
        $first = $this->orderFor($customer);
        $second = $this->orderFor($customer);

        $this->actingAs($admin)
            ->patch(route('admin.orders.bulk'), [
                'status' => 'cancelled',
                'order_ids' => [$first->id, $second->id],
            ])
            ->assertSessionHas('status');

        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('cancelled', $second->fresh()->status);

        // Audit trail: one summary row for the run.
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'orders.bulk_status',
            'user_id' => $admin->id,
        ]);
    }

    public function test_bulk_status_change_skips_illegal_transitions_and_reports_them(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();

        // "out_for_delivery" is legal from confirmed but not from pending.
        $confirmed = $this->orderFor($customer, ['status' => 'confirmed']);
        $pending = $this->orderFor($customer, ['status' => 'pending']);

        $this->actingAs($admin)
            ->patch(route('admin.orders.bulk'), [
                'status' => 'out_for_delivery',
                'order_ids' => [$confirmed->id, $pending->id],
            ])
            ->assertSessionHas('status');

        $this->assertSame('out_for_delivery', $confirmed->fresh()->status);
        $this->assertSame('pending', $pending->fresh()->status, 'The state machine must refuse the illegal jump.');

        // The message names the skipped order so nothing happens silently.
        $this->assertStringContainsString('Skipped', session('status'));
        $this->assertStringContainsString($pending->order_number, session('status'));
    }

    public function test_bulk_close_is_refused_while_a_return_is_still_awaiting_review(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();
        $order = $this->orderFor($customer, ['status' => 'delivered']);
        $product = $this->product();

        ProductReturn::create([
            'product_id' => $product->id,
            'order_id' => $order->id,
            'quantity' => 1,
            'reason' => 'Damaged bag.',
            'source' => 'customer',
            'status' => 'pending',
            'returned_by' => $customer->id,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.orders.bulk'), [
                'status' => 'closed',
                'order_ids' => [$order->id],
            ])
            ->assertSessionHas('error');

        $this->assertSame('delivered', $order->fresh()->status, 'An open return blocks closing, exactly like the single form.');
        $this->assertStringContainsString('return', session('error'));
    }

    public function test_bulk_status_change_mirrors_into_the_delivery_record(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();
        $order = $this->orderFor($customer, ['status' => 'confirmed']);
        $delivery = $order->delivery()->create(['status' => 'confirmed']);

        $this->actingAs($admin)
            ->patch(route('admin.orders.bulk'), [
                'status' => 'out_for_delivery',
                'order_ids' => [$order->id],
            ])
            ->assertSessionHas('status');

        $this->assertSame('out_for_delivery', $order->fresh()->status);
        $this->assertSame('out_for_delivery', $delivery->fresh()->status, 'The two screens must never disagree.');
    }

    public function test_an_unknown_status_is_rejected_before_anything_moves(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $order = $this->orderFor($this->customer());

        $this->actingAs($admin)
            ->patch(route('admin.orders.bulk'), [
                'status' => 'teleported',
                'order_ids' => [$order->id],
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame('pending', $order->fresh()->status);
    }

    /* ================================================================
     | PHASE 13 — deliveries
     * ============================================================= */

    public function test_guests_cannot_use_the_delivery_bulk_route(): void
    {
        $this->patch(route('admin.deliveries.bulk'), [
            'status' => 'confirmed',
            'delivery_ids' => [1],
        ])->assertRedirect(route('login'));
    }

    public function test_bulk_confirm_moves_every_eligible_delivery_and_is_audited(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();

        $first = $this->orderFor($customer)->delivery()->create(['status' => 'pending']);
        $second = $this->orderFor($customer)->delivery()->create(['status' => 'pending']);

        $this->actingAs($admin)
            ->patch(route('admin.deliveries.bulk'), [
                'status' => 'confirmed',
                'delivery_ids' => [$first->id, $second->id],
            ])
            ->assertSessionHas('status');

        $this->assertSame('confirmed', $first->fresh()->status);
        $this->assertSame('confirmed', $second->fresh()->status);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'deliveries.bulk_status',
            'user_id' => $admin->id,
        ]);
    }

    public function test_bulk_delivered_requires_a_received_by_name(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $delivery = $this->orderFor($this->customer())->delivery()->create(['status' => 'out_for_delivery']);

        $this->actingAs($admin)
            ->patch(route('admin.deliveries.bulk'), [
                'status' => 'delivered',
                'delivery_ids' => [$delivery->id],
            ])
            ->assertSessionHasErrors('received_by');

        $this->assertSame('out_for_delivery', $delivery->fresh()->status);
    }

    public function test_bulk_delivered_stamps_the_confirmation_record_and_mirrors_the_order(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();
        $order = $this->orderFor($customer, ['status' => 'out_for_delivery']);
        $delivery = $order->delivery()->create(['status' => 'out_for_delivery']);

        $this->actingAs($admin)
            ->patch(route('admin.deliveries.bulk'), [
                'status' => 'delivered',
                'received_by' => 'Chidi Okonkwo',
                'delivery_ids' => [$delivery->id],
            ])
            ->assertSessionHas('status');

        $fresh = $delivery->fresh();
        $this->assertSame('delivered', $fresh->status);
        $this->assertSame('Chidi Okonkwo', $fresh->received_by);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_bulk_skips_deliveries_already_in_the_target_status(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();
        $already = $this->orderFor($customer)->delivery()->create(['status' => 'confirmed']);
        $pending = $this->orderFor($customer)->delivery()->create(['status' => 'pending']);

        $this->actingAs($admin)
            ->patch(route('admin.deliveries.bulk'), [
                'status' => 'confirmed',
                'delivery_ids' => [$already->id, $pending->id],
            ])
            ->assertSessionHas('status');

        $this->assertSame('confirmed', $already->fresh()->status);
        $this->assertSame('confirmed', $pending->fresh()->status);
        $this->assertStringContainsString('Skipped', session('status'));
    }

    public function test_bulk_failed_is_refused_because_it_needs_a_per_delivery_reason(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $delivery = $this->orderFor($this->customer())->delivery()->create(['status' => 'out_for_delivery']);

        $this->actingAs($admin)
            ->patch(route('admin.deliveries.bulk'), [
                'status' => 'failed',
                'delivery_ids' => [$delivery->id],
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame('out_for_delivery', $delivery->fresh()->status);
    }

    public function test_bulk_delivery_update_notifies_the_customer_when_the_order_moves(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();
        $order = $this->orderFor($customer, ['status' => 'confirmed']);
        $delivery = $order->delivery()->create(['status' => 'confirmed']);

        $this->actingAs($admin)
            ->patch(route('admin.deliveries.bulk'), [
                'status' => 'out_for_delivery',
                'delivery_ids' => [$delivery->id],
            ])
            ->assertSessionHas('status');

        Notification::assertSentTo($customer, OrderStatusChanged::class);
    }

    /* ================================================================
     | PHASE 15 — returns
     * ============================================================= */

    public function test_guests_cannot_use_the_return_bulk_route(): void
    {
        $this->post(route('admin.returns.bulk'), [
            'action' => 'review',
            'return_ids' => [1],
        ])->assertRedirect(route('login'));
    }

    public function test_bulk_review_moves_pending_returns_to_in_review(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product();

        $first = ProductReturn::create([
            'product_id' => $product->id,
            'quantity' => 1,
            'reason' => 'Wrong item.',
            'source' => 'staff',
            'status' => 'pending',
            'returned_by' => $admin->id,
        ]);
        $second = ProductReturn::create([
            'product_id' => $product->id,
            'quantity' => 2,
            'reason' => 'Damaged.',
            'source' => 'staff',
            'status' => 'pending',
            'returned_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.returns.bulk'), [
                'action' => 'review',
                'return_ids' => [$first->id, $second->id],
            ])
            ->assertSessionHas('status');

        $this->assertSame('in_review', $first->fresh()->status);
        $this->assertSame('in_review', $second->fresh()->status);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'returns.bulk_review',
            'user_id' => $admin->id,
        ]);
    }

    public function test_bulk_approve_accepts_open_requests_and_notifies_the_customer(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();
        $order = $this->orderFor($customer, ['status' => 'delivered']);
        $product = $this->product();

        $pending = ProductReturn::create([
            'product_id' => $product->id,
            'order_id' => $order->id,
            'quantity' => 1,
            'reason' => 'Damaged bag.',
            'source' => 'customer',
            'status' => 'pending',
            'returned_by' => $customer->id,
        ]);
        $inReview = ProductReturn::create([
            'product_id' => $product->id,
            'order_id' => $order->id,
            'quantity' => 1,
            'reason' => 'Wrong size.',
            'source' => 'customer',
            'status' => 'in_review',
            'returned_by' => $customer->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.returns.bulk'), [
                'action' => 'approve',
                'return_ids' => [$pending->id, $inReview->id],
            ])
            ->assertSessionHas('status');

        $this->assertSame('approved', $pending->fresh()->status);
        $this->assertSame('approved', $inReview->fresh()->status);
        $this->assertNotNull($pending->fresh()->reviewed_at);

        Notification::assertSentTo($customer, ReturnStatusUpdated::class);
    }

    public function test_bulk_decline_rejects_open_requests(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product();

        $return = ProductReturn::create([
            'product_id' => $product->id,
            'quantity' => 1,
            'reason' => 'Changed my mind.',
            'source' => 'staff',
            'status' => 'pending',
            'returned_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.returns.bulk'), [
                'action' => 'decline',
                'return_ids' => [$return->id],
            ])
            ->assertSessionHas('status');

        $this->assertSame('rejected', $return->fresh()->status);
    }

    public function test_bulk_approve_skips_returns_that_were_already_reviewed(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product();

        $reviewed = ProductReturn::create([
            'product_id' => $product->id,
            'quantity' => 1,
            'reason' => 'Damaged.',
            'source' => 'staff',
            'status' => 'approved',
            'returned_by' => $admin->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);
        $pending = ProductReturn::create([
            'product_id' => $product->id,
            'quantity' => 1,
            'reason' => 'Damaged.',
            'source' => 'staff',
            'status' => 'pending',
            'returned_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.returns.bulk'), [
                'action' => 'approve',
                'return_ids' => [$reviewed->id, $pending->id],
            ])
            ->assertSessionHas('status');

        $this->assertSame('approved', $reviewed->fresh()->status);
        $this->assertSame('approved', $pending->fresh()->status);
        $this->assertStringContainsString('Skipped', session('status'));
    }

    public function test_bulk_review_never_moves_a_completed_return_backwards(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product();

        $completed = ProductReturn::create([
            'product_id' => $product->id,
            'quantity' => 1,
            'reason' => 'Damaged.',
            'source' => 'staff',
            'status' => 'completed',
            'returned_by' => $admin->id,
            'restocked_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.returns.bulk'), [
                'action' => 'review',
                'return_ids' => [$completed->id],
            ])
            ->assertSessionHas('error');

        $this->assertSame('completed', $completed->fresh()->status);
    }

    public function test_an_unknown_return_action_is_rejected(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product();
        $return = ProductReturn::create([
            'product_id' => $product->id,
            'quantity' => 1,
            'reason' => 'Damaged.',
            'source' => 'staff',
            'status' => 'pending',
            'returned_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.returns.bulk'), [
                'action' => 'teleport',
                'return_ids' => [$return->id],
            ])
            ->assertSessionHasErrors('action');

        $this->assertSame('pending', $return->fresh()->status);
    }

    /* ================================================================
     | PHASE 16 — support tickets
     * ============================================================= */

    public function test_guests_cannot_use_the_support_bulk_route(): void
    {
        $this->patch(route('admin.support.bulk'), [
            'action' => 'start_progress',
            'ticket_ids' => [1],
        ])->assertRedirect(route('login'));
    }

    public function test_bulk_start_progress_moves_open_tickets_only(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();

        $open = $this->ticket($customer);
        $inProgress = $this->ticket($customer, ['status' => 'in_progress']);

        $this->actingAs($admin)
            ->patch(route('admin.support.bulk'), [
                'action' => 'start_progress',
                'ticket_ids' => [$open->id, $inProgress->id],
            ])
            ->assertSessionHas('status');

        $this->assertSame('in_progress', $open->fresh()->status);
        $this->assertSame('in_progress', $inProgress->fresh()->status);
        $this->assertStringContainsString('Skipped', session('status'));

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'support.bulk_progress',
            'user_id' => $admin->id,
        ]);
    }

    public function test_bulk_resolve_moves_open_and_in_progress_tickets_and_notifies(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();

        $open = $this->ticket($customer);
        $inProgress = $this->ticket($customer, ['status' => 'in_progress']);

        $this->actingAs($admin)
            ->patch(route('admin.support.bulk'), [
                'action' => 'resolve',
                'ticket_ids' => [$open->id, $inProgress->id],
            ])
            ->assertSessionHas('status');

        $this->assertSame('resolved', $open->fresh()->status);
        $this->assertSame('resolved', $inProgress->fresh()->status);
        $this->assertNotNull($open->fresh()->resolved_at);

        Notification::assertSentTo($customer, SupportTicketUpdated::class);
    }

    public function test_bulk_resolve_skips_tickets_already_resolved(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();

        $resolved = $this->ticket($customer, [
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);
        $open = $this->ticket($customer);

        $this->actingAs($admin)
            ->patch(route('admin.support.bulk'), [
                'action' => 'resolve',
                'ticket_ids' => [$resolved->id, $open->id],
            ])
            ->assertSessionHas('status');

        $this->assertSame('resolved', $resolved->fresh()->status);
        $this->assertSame('resolved', $open->fresh()->status);
        $this->assertStringContainsString('Skipped', session('status'));
    }

    public function test_an_unknown_support_action_is_rejected(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = $this->customer();
        $open = $this->ticket($customer);

        $this->actingAs($admin)
            ->patch(route('admin.support.bulk'), [
                'action' => 'teleport',
                'ticket_ids' => [$open->id],
            ])
            ->assertSessionHasErrors('action');

        $this->assertSame('open', $open->fresh()->status);
    }

    /* ================================================================
     | PHASE 11 — categories
     * ============================================================= */

    public function test_guests_cannot_use_the_category_bulk_route(): void
    {
        $this->post(route('admin.categories.bulk'), [
            'action' => 'delete',
            'category_ids' => [1],
        ])->assertRedirect(route('login'));
    }

    public function test_sales_staff_cannot_use_the_category_bulk_route(): void
    {
        $sales = $this->staff(User::ROLE_SALES);

        $this->actingAs($sales)
            ->post(route('admin.categories.bulk'), [
                'action' => 'delete',
                'category_ids' => [1],
            ])
            ->assertForbidden();
    }

    public function test_bulk_delete_removes_empty_categories_and_is_audited(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);

        $first = Category::create(['name' => 'Empty One', 'slug' => 'empty-one']);
        $second = Category::create(['name' => 'Empty Two', 'slug' => 'empty-two']);

        $this->actingAs($admin)
            ->post(route('admin.categories.bulk'), [
                'action' => 'delete',
                'category_ids' => [$first->id, $second->id],
            ])
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('categories', ['id' => $first->id]);
        $this->assertDatabaseMissing('categories', ['id' => $second->id]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'categories.bulk_delete',
            'user_id' => $admin->id,
        ]);
    }

    public function test_bulk_delete_refuses_categories_that_still_have_products(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product();
        $inUse = Category::findOrFail($product->category_id);
        $empty = Category::create(['name' => 'Still Empty', 'slug' => 'still-empty']);

        $this->actingAs($admin)
            ->post(route('admin.categories.bulk'), [
                'action' => 'delete',
                'category_ids' => [$inUse->id, $empty->id],
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $inUse->id]);
        $this->assertDatabaseMissing('categories', ['id' => $empty->id]);
        $this->assertStringContainsString('Skipped', session('error'));
    }

    public function test_an_unknown_category_action_is_rejected(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $category = Category::create(['name' => 'Kept', 'slug' => 'kept']);

        $this->actingAs($admin)
            ->post(route('admin.categories.bulk'), [
                'action' => 'teleport',
                'category_ids' => [$category->id],
            ])
            ->assertSessionHasErrors('action');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }
}

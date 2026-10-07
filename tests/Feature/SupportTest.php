<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketCreated;
use App\Notifications\SupportTicketUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 6 — Customer Care: a structured complaint workflow.
 *
 * The customer can report problems (linked to their own orders only),
 * staff respond and move the record through its real lifecycle, and
 * nothing resolves itself just because a notification went out.
 */
class SupportTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------
     | Helpers
     * ---------------------------------------------------------------- */

    private function customer(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
        ], $attrs));
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function orderFor(User $customer, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'HBM-'.strtoupper(substr(uniqid(), -6)),
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '08031234567',
            'delivery_address' => '12 Ogui Road, Enugu',
            'delivery_option' => 'delivery',
            'preferred_delivery_date' => now()->addDay()->toDateString(),
            'payment_option' => 'pay_on_delivery',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 4000,
            'delivery_fee' => 0,
            'total' => 4000,
        ], $attrs));
    }

    private function fileTicket(User $customer, array $overrides = []): array
    {
        return array_merge([
            'category' => 'delivery_issue',
            'subject' => 'Cement order never arrived',
            'description' => 'The delivery driver never showed up on Tuesday and nobody called me back.',
        ], $overrides);
    }

    /* ------------------------------------------------------------------
     | Customer side
     * ---------------------------------------------------------------- */

    public function test_the_customer_care_page_shows_real_contact_details(): void
    {
        $page = $this->actingAs($this->customer())->get(route('support.index'));

        $page->assertOk()
            ->assertSee('tel:+2348153667923')
            ->assertSee('mailto:humphreybuildingmaterials@gmail.com')
            ->assertSee('Call Us')
            ->assertSee('Eda plaza beside abacha road mararaba, nasarawa');
    }

    public function test_a_customer_can_file_a_complaint_and_staff_are_alerted(): void
    {
        Notification::fake();

        $customer = $this->customer();
        $admin = $this->staff(User::ROLE_ADMIN);

        $response = $this->actingAs($customer)
            ->post(route('support.store'), $this->fileTicket($customer));

        $ticket = SupportTicket::first();
        $this->assertNotNull($ticket);

        $response->assertRedirect(route('support.show', $ticket))
            ->assertSessionHas('status');

        $this->assertSame('open', $ticket->status);
        $this->assertSame($customer->id, $ticket->user_id);
        $this->assertMatchesRegularExpression('/^SUP-\d{4}-\d{6}$/', $ticket->reference);
        $this->assertSame($customer->email, $ticket->customer_email);

        Notification::assertSentTo($admin, SupportTicketCreated::class);
        // The customer is NOT told "resolved" — filing a complaint changes nothing for them yet.
        Notification::assertNotSentTo($customer, SupportTicketUpdated::class);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'support.created',
            'subject_id' => $ticket->id,
        ]);
    }

    public function test_filing_requires_the_essential_details(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)
            ->post(route('support.store'), [
                'category' => 'not_a_category',
                'subject' => '',
                'description' => 'too short',
            ])
            ->assertSessionHasErrors(['category', 'subject', 'description']);

        $this->assertSame(0, SupportTicket::count());
    }

    public function test_a_complaint_can_reference_one_of_the_customers_own_orders(): void
    {
        $customer = $this->customer();
        $order = $this->orderFor($customer);

        $this->actingAs($customer)
            ->post(route('support.store'), $this->fileTicket($customer, [
                'order_reference' => $order->order_number,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($order->id, SupportTicket::first()->order_id);
    }

    public function test_an_order_reference_from_another_account_is_rejected(): void
    {
        Notification::fake();

        $customer = $this->customer();
        $someoneElsesOrder = $this->orderFor($this->customer());

        $this->actingAs($customer)
            ->post(route('support.store'), $this->fileTicket($customer, [
                'order_reference' => $someoneElsesOrder->order_number,
            ]))
            ->assertSessionHasErrors('order_reference');

        $this->assertSame(0, SupportTicket::count());
    }

    public function test_a_made_up_order_reference_is_rejected(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)
            ->post(route('support.store'), $this->fileTicket($customer, [
                'order_reference' => 'HBM-9999-NOPE',
            ]))
            ->assertSessionHasErrors('order_reference');

        $this->assertSame(0, SupportTicket::count());
    }

    public function test_customers_can_only_see_their_own_requests(): void
    {
        $owner = $this->customer();
        $other = $this->customer();

        $ticket = SupportTicket::create([
            'reference' => 'SUP-2026-000001',
            'user_id' => $owner->id,
            'customer_name' => $owner->name,
            'customer_email' => $owner->email,
            'category' => 'order_issue',
            'subject' => 'Wrong item',
            'description' => 'I received the wrong item entirely, please sort this out.',
            'status' => 'open',
        ]);

        $this->actingAs($owner)->get(route('support.show', $ticket))->assertOk();
        $this->actingAs($other)->get(route('support.show', $ticket))->assertNotFound();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('support.index'))->assertRedirect(route('login'));

        $this->post(route('support.store'), $this->fileTicket($this->customer()))
            ->assertRedirect(route('login'));

        $this->assertSame(0, SupportTicket::count());
    }

    /* ------------------------------------------------------------------
     | Staff side
     * ---------------------------------------------------------------- */

    public function test_admin_and_sales_can_manage_requests_but_inventory_cannot(): void
    {
        $ticket = SupportTicket::create([
            'reference' => 'SUP-2026-000002',
            'user_id' => null,
            'customer_name' => 'Walk-in Customer',
            'customer_email' => 'walkin@example.test',
            'category' => 'general_enquiry',
            'subject' => 'Do you sell tiles?',
            'description' => 'Do you stock 40x40 floor tiles and what is the price per pack?',
            'status' => 'open',
        ]);

        $this->actingAs($this->staff(User::ROLE_INVENTORY))
            ->get(route('admin.support.index'))
            ->assertForbidden();

        $this->actingAs($this->staff(User::ROLE_INVENTORY))
            ->get(route('admin.support.show', $ticket))
            ->assertForbidden();

        $this->actingAs($this->staff(User::ROLE_SALES))->get(route('admin.support.index'))->assertOk();
        $this->actingAs($this->staff(User::ROLE_ADMIN))->get(route('admin.support.show', $ticket))->assertOk();
    }

    public function test_the_sidebar_link_is_shown_to_the_right_roles(): void
    {
        $link = 'href="'.route('admin.support.index').'"';

        $this->actingAs($this->staff(User::ROLE_SALES))
            ->get(route('admin.dashboard'))
            ->assertSee($link, false);

        $this->actingAs($this->staff(User::ROLE_INVENTORY))
            ->get(route('admin.dashboard'))
            ->assertDontSee($link, false);
    }

    public function test_staff_can_respond_and_change_status_and_the_customer_is_told(): void
    {
        Notification::fake();

        $customer = $this->customer();
        $sales = $this->staff(User::ROLE_SALES);

        $ticket = SupportTicket::create([
            'reference' => 'SUP-2026-000003',
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'category' => 'delivery_issue',
            'subject' => 'Driver never came',
            'description' => 'Nobody delivered my order and nobody called me back about it.',
            'status' => 'open',
        ]);

        $this->actingAs($sales)
            ->patch(route('admin.support.update', $ticket), [
                'status' => 'resolved',
                'staff_response' => 'We re-delivered on Wednesday morning. Apologies for the delay.',
            ])
            ->assertRedirect(route('admin.support.show', $ticket))
            ->assertSessionHas('status');

        $ticket->refresh();
        $this->assertSame('resolved', $ticket->status);
        $this->assertSame($sales->id, $ticket->responded_by);
        $this->assertNotNull($ticket->responded_at);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertStringContainsString('re-delivered', $ticket->staff_response);

        Notification::assertSentTo($customer, SupportTicketUpdated::class);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'support.updated',
            'subject_id' => $ticket->id,
        ]);
    }

    public function test_nothing_changes_when_staff_submit_an_empty_update(): void
    {
        Notification::fake();

        $customer = $this->customer();
        $admin = $this->staff(User::ROLE_ADMIN);

        $ticket = SupportTicket::create([
            'reference' => 'SUP-2026-000004',
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'category' => 'other',
            'subject' => 'Idle',
            'description' => 'Just checking in with nothing really to report here.',
            'status' => 'open',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.support.update', $ticket), [
                'status' => 'open',
                'staff_response' => '',
            ])
            ->assertSessionHas('error');

        $ticket->refresh();
        $this->assertSame('open', $ticket->status);
        $this->assertNull($ticket->responded_at);

        Notification::assertNothingSent();
    }

    public function test_resolving_is_always_deliberate_never_a_side_effect(): void
    {
        $customer = $this->customer();

        $ticket = SupportTicket::create([
            'reference' => 'SUP-2026-000005',
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'category' => 'payment_issue',
            'subject' => 'Double charged',
            'description' => 'It looks like my card was charged twice for the same order.',
            'status' => 'open',
        ]);

        // Opening the record (even as staff) never resolves it.
        $this->actingAs($this->staff(User::ROLE_SALES))
            ->get(route('admin.support.show', $ticket))
            ->assertOk();

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->resolved_at);
    }

    public function test_a_status_change_without_a_reply_still_notifies_the_customer(): void
    {
        Notification::fake();

        $customer = $this->customer();
        $admin = $this->staff(User::ROLE_ADMIN);

        $ticket = SupportTicket::create([
            'reference' => 'SUP-2026-000006',
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'category' => 'general_enquiry',
            'subject' => 'Bulk pricing question',
            'description' => 'What price do you give for an order of five hundred bags of cement?',
            'status' => 'open',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.support.update', $ticket), [
                'status' => 'in_progress',
                'staff_response' => '',
            ])
            ->assertSessionHas('status');

        $this->assertSame('in_progress', $ticket->fresh()->status);
        Notification::assertSentTo($customer, SupportTicketUpdated::class);
    }

    public function test_the_admin_list_can_be_filtered(): void
    {
        SupportTicket::create([
            'reference' => 'SUP-2026-000007',
            'customer_name' => 'Ada Obi',
            'customer_email' => 'ada@example.test',
            'category' => 'product_issue',
            'subject' => 'Cracked tiles in the box',
            'description' => 'Three tiles arrived cracked in an unopened box of sixty.',
            'status' => 'open',
        ]);
        SupportTicket::create([
            'reference' => 'SUP-2026-000008',
            'customer_name' => 'Bola Ade',
            'customer_email' => 'bola@example.test',
            'category' => 'general_enquiry',
            'subject' => 'Delivery days',
            'description' => 'Which days of the week do you deliver to Nyanya?',
            'status' => 'closed',
        ]);

        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.support.index', ['status' => 'open']))
            ->assertOk()
            ->assertSee('SUP-2026-000007')
            ->assertDontSee('SUP-2026-000008');

        $this->actingAs($admin)
            ->get(route('admin.support.index', ['search' => 'Bola']))
            ->assertOk()
            ->assertSee('SUP-2026-000008')
            ->assertDontSee('SUP-2026-000007');
    }
}

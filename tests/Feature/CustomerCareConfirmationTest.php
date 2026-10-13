<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Customer-care submissions confirm professionally — but only after the
 * server really accepted and saved the message.
 */
class CustomerCareConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private const TITLE = 'Message Submitted Successfully';

    private const GENERAL_WORDING = 'Thank you for contacting Humphrey Building Materials. '
        .'We have received your message and our customer care team will review your request '
        .'and get back to you as soon as possible.';

    private const COMPLAINT_WORDING = 'Thank you for bringing this to our attention. '
        .'Your complaint has been received, and our customer care team will review it '
        .'and get back to you as soon as possible.';

    private function customer(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
        ], $attrs));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category' => 'general_enquiry',
            'subject' => 'Do you sell tiles in bulk?',
            'description' => 'Please confirm whether 40x40 floor tiles are available this week.',
        ], $overrides);
    }

    /* ------------------------------------------------------------------
     | Success
     * ---------------------------------------------------------------- */

    public function test_a_successful_general_enquiry_shows_the_professional_confirmation(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)
            ->post(route('support.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $ticket = SupportTicket::firstOrFail();

        $this->actingAs($customer)
            ->get(route('support.show', $ticket))
            ->assertOk()
            ->assertSee(self::TITLE)
            ->assertSee(self::GENERAL_WORDING, false)
            ->assertSee($ticket->reference)
            ->assertSee('please keep it');
    }

    public function test_a_complaint_shows_the_complaint_wording(): void
    {
        Notification::fake();

        $customer = $this->customer();
        $staff = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        $this->actingAs($customer)
            ->post(route('support.store'), $this->payload([
                'category' => 'delivery_issue',
                'subject' => 'Cement order never arrived',
                'description' => 'The delivery driver never showed up on Tuesday and nobody called back.',
            ]))
            ->assertSessionHasNoErrors();

        $ticket = SupportTicket::firstOrFail();

        $this->actingAs($customer)
            ->get(route('support.show', $ticket))
            ->assertOk()
            ->assertSee(self::TITLE)
            ->assertSee(self::COMPLAINT_WORDING, false)
            ->assertDontSee(self::GENERAL_WORDING, false)
            ->assertSee($ticket->reference);

        // Everything the workflow relied on before is still intact.
        $this->assertSame('open', $ticket->status);
        $this->assertMatchesRegularExpression('/^SUP-\d{4}-\d{6}$/', $ticket->reference);
        Notification::assertSentTo($staff, SupportTicketCreated::class);
    }

    public function test_the_confirmation_panel_carries_the_reference_number(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)->post(route('support.store'), $this->payload());

        $ticket = SupportTicket::firstOrFail();

        $this->actingAs($customer)
            ->get(route('support.show', $ticket))
            ->assertSee('Your reference number is', false)
            ->assertSee('<strong>'.$ticket->reference.'</strong>', false);
    }

    /* ------------------------------------------------------------------
     | Failure never shows a success
     * ---------------------------------------------------------------- */

    public function test_failed_validation_never_shows_a_success_message(): void
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

        $this->actingAs($customer)
            ->get(route('support.index'))
            ->assertOk()
            ->assertDontSee(self::TITLE)
            ->assertDontSee(self::GENERAL_WORDING, false);
    }

    public function test_a_rejected_order_reference_never_shows_a_success_message(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)
            ->post(route('support.store'), $this->payload(['order_reference' => 'HBM-9999-NOPE']))
            ->assertSessionHasErrors('order_reference');

        $this->assertSame(0, SupportTicket::count());

        $this->actingAs($customer)
            ->get(route('support.index'))
            ->assertOk()
            ->assertDontSee(self::TITLE);
    }

    /* ------------------------------------------------------------------
     | Accidental repeats
     * ---------------------------------------------------------------- */

    public function test_an_accidental_repeat_does_not_open_a_second_ticket(): void
    {
        $customer = $this->customer();
        $payload = $this->payload();

        $first = $this->actingAs($customer)->post(route('support.store'), $payload);
        $first->assertSessionHas('status');

        $second = $this->actingAs($customer)->post(route('support.store'), $payload);
        $second->assertSessionHas('status');

        $this->assertSame(1, SupportTicket::count());

        $ticket = SupportTicket::firstOrFail();

        // Both sends land on the same record and its reference.
        $first->assertRedirect(route('support.show', $ticket));
        $second->assertRedirect(route('support.show', $ticket));

        $this->actingAs($customer)
            ->get(route('support.show', $ticket))
            ->assertOk()
            ->assertSee(self::TITLE)
            ->assertSee($ticket->reference);
    }

    public function test_the_send_button_locks_after_the_first_click(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)
            ->get(route('support.index'))
            ->assertOk()
            ->assertSee('send.disabled=true', false);
    }
}

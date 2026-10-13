<?php

namespace Tests\Feature;

use App\Models\BusinessContact;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 5 — the product page's "Call to Order" action is admin-configurable.
 *
 * Admin picks which customer-care contact's number is dialed. The selection
 * survives nothing: deactivating or deleting that contact always falls back
 * to the business_phone owner number, so the button never dials a dead line.
 */
class CallToOrderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    private function contact(array $attrs = []): BusinessContact
    {
        static $i = 0;
        $i++;

        return BusinessContact::create(array_merge([
            'label' => 'Enquiries '.$i,
            'phone' => '+234801111222'.$i,
            'status' => 'active',
            'contact_order' => $i,
        ], $attrs));
    }

    /* ------------------------------------------------------------------
     | Selecting the destination
     * ---------------------------------------------------------------- */

    public function test_selecting_an_active_contact_makes_it_the_product_enquiry_number(): void
    {
        $admin = $this->admin();
        $contact = $this->contact(['phone' => '+2348099998888']);

        $this->actingAs($admin)
            ->patch(route('admin.contacts.call-to-order'), ['contact_id' => $contact->id])
            ->assertSessionHas('status');

        $this->assertSame($contact->id, (int) Setting::get(BusinessContact::CALL_TO_ORDER_SETTING));
        $this->assertSame('+2348099998888', BusinessContact::callToOrderPhone());
        $this->assertTrue($contact->is(BusinessContact::callToOrderContact()));
    }

    public function test_the_selection_is_independent_of_the_homepage_first_contact(): void
    {
        $admin = $this->admin();
        // Migrations ship a "Customer Care" contact already, so use a high
        // order number to own the tail of the homepage list deterministically.
        $homeLead = BusinessContact::active()->orderBy('contact_order')->orderBy('id')->firstOrFail();
        $enquiry = $this->contact(['contact_order' => 900, 'phone' => '+2348077776666']);

        $this->actingAs($admin)
            ->patch(route('admin.contacts.call-to-order'), ['contact_id' => $enquiry->id])
            ->assertSessionHas('status');

        // The homepage still leads with its first contact…
        $this->assertTrue($homeLead->is(BusinessContact::active()->orderBy('contact_order')->orderBy('id')->first()));

        // …while product enquiries dial the selected one, which is nowhere
        // near the top of the homepage list.
        $this->assertSame($enquiry->id, (int) Setting::get(BusinessContact::CALL_TO_ORDER_SETTING));
        $this->assertSame('+2348077776666', BusinessContact::callToOrderPhone());
    }

    public function test_resetting_the_selection_falls_back_to_the_business_phone(): void
    {
        $admin = $this->admin();
        $contact = $this->contact();
        Setting::set('business_phone', '+2348153667923');

        $this->actingAs($admin)
            ->patch(route('admin.contacts.call-to-order'), ['contact_id' => $contact->id])
            ->assertSessionHas('status');

        $this->actingAs($admin)
            ->patch(route('admin.contacts.call-to-order'), ['contact_id' => null])
            ->assertSessionHas('status');

        $this->assertNull(Setting::get(BusinessContact::CALL_TO_ORDER_SETTING));
        $this->assertSame('+2348153667923', BusinessContact::callToOrderPhone());
        $this->assertNull(BusinessContact::callToOrderContact());
    }

    public function test_an_inactive_contact_cannot_be_selected(): void
    {
        $admin = $this->admin();
        $contact = $this->contact(['status' => 'inactive']);

        $this->actingAs($admin)
            ->patch(route('admin.contacts.call-to-order'), ['contact_id' => $contact->id])
            ->assertSessionHasErrors('contact_id');

        $this->assertNull(Setting::get(BusinessContact::CALL_TO_ORDER_SETTING));
    }

    public function test_only_an_admin_can_choose_the_destination(): void
    {
        $sales = User::factory()->create(['role' => User::ROLE_SALES, 'is_active' => true]);
        $contact = $this->contact();

        $this->actingAs($sales)
            ->patch(route('admin.contacts.call-to-order'), ['contact_id' => $contact->id])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------
     | The selection can never dial a dead line
     * ---------------------------------------------------------------- */

    public function test_deactivating_the_selected_contact_falls_back_to_the_business_phone(): void
    {
        $admin = $this->admin();
        $contact = $this->contact(['phone' => '+2348055554444']);
        Setting::set('business_phone', '+2348153667923');

        $this->actingAs($admin)
            ->patch(route('admin.contacts.call-to-order'), ['contact_id' => $contact->id])
            ->assertSessionHas('status');
        $this->assertSame('+2348055554444', BusinessContact::callToOrderPhone());

        $this->actingAs($admin)
            ->post(route('admin.contacts.toggle', $contact))
            ->assertSessionHas('status');

        $this->assertNull(Setting::get(BusinessContact::CALL_TO_ORDER_SETTING));
        $this->assertSame('+2348153667923', BusinessContact::callToOrderPhone());
    }

    public function test_deleting_the_selected_contact_falls_back_to_the_business_phone(): void
    {
        $admin = $this->admin();
        $contact = $this->contact();
        Setting::set('business_phone', '+2348153667923');

        $this->actingAs($admin)
            ->patch(route('admin.contacts.call-to-order'), ['contact_id' => $contact->id])
            ->assertSessionHas('status');

        $this->actingAs($admin)
            ->delete(route('admin.contacts.destroy', $contact))
            ->assertSessionHas('status');

        $this->assertNull(Setting::get(BusinessContact::CALL_TO_ORDER_SETTING));
        $this->assertSame('+2348153667923', BusinessContact::callToOrderPhone());
    }

    public function test_bulk_deactivating_the_selected_contact_falls_back(): void
    {
        $admin = $this->admin();
        $selected = $this->contact();
        $other = $this->contact();
        Setting::set('business_phone', '+2348153667923');

        $this->actingAs($admin)
            ->patch(route('admin.contacts.call-to-order'), ['contact_id' => $selected->id])
            ->assertSessionHas('status');

        $this->actingAs($admin)
            ->post(route('admin.contacts.bulk'), [
                'action' => 'deactivate',
                'contact_ids' => [$selected->id, $other->id],
            ])
            ->assertSessionHas('status');

        $this->assertNull(Setting::get(BusinessContact::CALL_TO_ORDER_SETTING));
        $this->assertSame('+2348153667923', BusinessContact::callToOrderPhone());
    }

    public function test_bulk_deleting_the_selected_contact_falls_back(): void
    {
        $admin = $this->admin();
        $selected = $this->contact();
        Setting::set('business_phone', '+2348153667923');

        $this->actingAs($admin)
            ->patch(route('admin.contacts.call-to-order'), ['contact_id' => $selected->id])
            ->assertSessionHas('status');

        $this->actingAs($admin)
            ->post(route('admin.contacts.bulk'), [
                'action' => 'delete',
                'contact_ids' => [$selected->id],
            ])
            ->assertSessionHas('status');

        $this->assertNull(Setting::get(BusinessContact::CALL_TO_ORDER_SETTING));
        $this->assertSame('+2348153667923', BusinessContact::callToOrderPhone());
    }

    public function test_a_deleted_or_deactivated_selection_never_wins_over_the_fallback(): void
    {
        // Even if the setting row were left behind pointing at a contact that
        // is no longer active, the resolved number is still the fallback.
        $contact = $this->contact(['status' => 'inactive']);
        Setting::set(BusinessContact::CALL_TO_ORDER_SETTING, (string) $contact->id);
        Setting::set('business_phone', '+2348153667923');

        $this->assertSame('+2348153667923', BusinessContact::callToOrderPhone());
        $this->assertNull(BusinessContact::callToOrderContact());
    }
}

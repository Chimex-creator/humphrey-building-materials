<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryArea;
use App\Models\DeliveryState;
use App\Models\Product;
use App\Models\User;
use App\Support\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PHASE 8 — delivery zone selection is state-first and cannot be spoofed.
 *
 * The customer picks a state, then (for zone states) an area inside it.
 * The server independently re-checks that the submitted area really belongs
 * to the submitted state and that the resulting location is serviceable;
 * anything it cannot confirm is refused with a "contact Customer Care"
 * message and no order is created.
 */
class CheckoutStateAreaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Checkout always hands off to Paystack — fake it so no test ever
        // reaches the real API.
        Http::fake(['*' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/fake-token',
                'access_code' => 'fake',
                'reference' => 'fake',
            ],
        ])]);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER, 'is_active' => true]);
    }

    /** A buyable product, already in the customer's cart. */
    private function seedCart(): Product
    {
        static $i = 0;
        $i++;

        $product = Product::create([
            'category_id' => Category::firstOrCreate(
                ['slug' => 'checkout-zone-test'],
                ['name' => 'Checkout Zone Test']
            )->id,
            'name' => 'Zone Test Cement '.$i,
            'slug' => 'zone-test-cement-'.$i,
            'price' => 4000,
            'stock_quantity' => 100,
            'status' => 'active',
        ]);

        Cart::add($product, 1);

        return $product;
    }

    private function zoneState(): DeliveryState
    {
        static $i = 0;
        $i++;

        return DeliveryState::create([
            'name' => 'Lagos (Zone Test '.$i.')',
            'uses_zones' => true,
            'status' => DeliveryState::STATUS_ACTIVE,
            'default_fee' => 0,
        ]);
    }

    private function area(DeliveryState $state, array $attrs = []): DeliveryArea
    {
        static $i = 0;
        $i++;

        $zone = \App\Models\DeliveryZone::create([
            'name' => 'Zone '.$i,
            'fee' => 2500,
            'status' => 'active',
        ]);

        return $state->areas()->create(array_merge([
            'name' => 'Area '.$i,
            'delivery_zone_id' => $zone->id,
            'status' => DeliveryArea::STATUS_ACTIVE,
        ], $attrs));
    }

    /** Post a delivery checkout for the given state/area pair. */
    private function postCheckout(User $user, ?int $stateId, ?int $areaId)
    {
        $this->seedCart();

        return $this->actingAs($user)->post(route('checkout.store'), [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '08031234567',
            'delivery_option' => 'delivery',
            'address' => '15 Admiralty Way, Lekki Phase 1',
            'preferred_delivery_date' => now()->addDay()->toDateString(),
            'delivery_state_id' => $stateId,
            'delivery_area_id' => $areaId,
        ]);
    }

    /* ------------------------------------------------------------------
     | The happy path still works
     * ---------------------------------------------------------------- */

    public function test_a_state_and_its_own_area_are_accepted(): void
    {
        $user = $this->customer();
        $state = $this->zoneState();
        $area = $this->area($state);

        $this->postCheckout($user, $state->id, $area->id)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'delivery_state_name' => $state->name,
            'delivery_area_name' => $area->name,
        ]);
    }

    /* ------------------------------------------------------------------
     | Cross-state mismatch is refused
     * ---------------------------------------------------------------- */

    public function test_an_area_from_a_different_state_is_refused(): void
    {
        $user = $this->customer();
        $stateA = $this->zoneState();
        $stateB = $this->zoneState();
        $foreignArea = $this->area($stateB);

        $this->postCheckout($user, $stateA->id, $foreignArea->id)
            ->assertSessionHasErrors('delivery_area_id');

        // The error is the Customer-Care message, never a guessed fee.
        $this->assertStringContainsString(
            'contact Customer Care',
            session('errors')->first('delivery_area_id')
        );

        $this->assertDatabaseMissing('orders', ['user_id' => $user->id]);
    }

    /* ------------------------------------------------------------------
     | Zone states always need their area
     * ---------------------------------------------------------------- */

    public function test_a_zone_state_without_an_area_is_refused(): void
    {
        $user = $this->customer();
        $state = $this->zoneState();
        $this->area($state);

        $this->postCheckout($user, $state->id, null)
            ->assertSessionHasErrors('delivery_area_id');

        $this->assertDatabaseMissing('orders', ['user_id' => $user->id]);
    }

    public function test_an_unknown_area_id_is_refused(): void
    {
        $user = $this->customer();
        $state = $this->zoneState();
        $this->area($state);

        $this->postCheckout($user, $state->id, 999999)
            ->assertSessionHasErrors('delivery_area_id');

        $this->assertStringContainsString(
            'contact Customer Care',
            session('errors')->first('delivery_area_id')
        );
        $this->assertDatabaseMissing('orders', ['user_id' => $user->id]);
    }

    public function test_an_unserviceable_area_is_refused_with_the_customer_care_message(): void
    {
        $user = $this->customer();
        $state = $this->zoneState();
        // An area with no zone behind it is not serviceable.
        $area = $this->area($state, ['delivery_zone_id' => null]);

        $this->postCheckout($user, $state->id, $area->id)
            ->assertSessionHasErrors('delivery_area_id');

        $this->assertStringContainsString(
            'contact Customer Care',
            session('errors')->first('delivery_area_id')
        );
        $this->assertDatabaseMissing('orders', ['user_id' => $user->id]);
    }

    /* ------------------------------------------------------------------
     | Non-zone states price from the state, area ignored
     * ---------------------------------------------------------------- */

    public function test_a_flat_state_is_accepted_without_any_area(): void
    {
        $user = $this->customer();
        $state = DeliveryState::create([
            'name' => 'Enugu (Flat Test)',
            'uses_zones' => false,
            'status' => DeliveryState::STATUS_ACTIVE,
            'default_fee' => 1500,
        ]);

        $this->postCheckout($user, $state->id, null)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'delivery_state_name' => 'Enugu (Flat Test)',
        ]);
    }

    /* ------------------------------------------------------------------
     | Delivery orders always need a state
     * ---------------------------------------------------------------- */

    public function test_a_delivery_order_without_a_state_is_refused(): void
    {
        $user = $this->customer();
        $this->zoneState();

        $this->postCheckout($user, null, null)
            ->assertSessionHasErrors('delivery_state_id');

        $this->assertDatabaseMissing('orders', ['user_id' => $user->id]);
    }

    /* ------------------------------------------------------------------
     | The zone lookup is driven by the submitted state alone
     * ---------------------------------------------------------------- */

    public function test_the_state_choice_alone_decides_which_areas_are_valid(): void
    {
        $user = $this->customer();
        $stateA = $this->zoneState();
        $stateB = $this->zoneState();
        $areaA = $this->area($stateA);
        $areaB = $this->area($stateB);

        // areaA is valid only against stateA…
        $this->postCheckout($user, $stateA->id, $areaA->id)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'delivery_area_name' => $areaA->name,
        ]);

        // …and the same area is refused the moment stateB is submitted.
        $this->postCheckout($user, $stateB->id, $areaA->id)
            ->assertSessionHasErrors('delivery_area_id');
    }
}

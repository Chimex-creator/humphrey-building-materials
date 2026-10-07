<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryArea;
use App\Models\DeliveryState;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Notifications\DeliveryFeeConfirmed;
use Database\Seeders\DeliveryZoneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phases 8–19 — the admin-configurable delivery zone system:
 * zones/states/areas management, server-side checkout pricing,
 * the order snapshot, unsupported locations and manual fee overrides.
 */
class DeliveryZonesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    /** Checkout reaches Paystack — fake it so tests never hit the real API. */
    private function fakePaystack(): void
    {
        Http::fake(['*' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/fake-token',
                'access_code' => 'fake',
                'reference' => 'fake',
            ],
        ])]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function buyer(): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER, 'is_active' => true]);
    }

    private function product(): Product
    {
        return Product::create([
            'category_id' => Category::firstOrCreate(
                ['slug' => 'materials'],
                ['name' => 'Materials']
            )->id,
            'name' => 'Dangote Cement',
            'slug' => 'dangote-cement-'.uniqid(),
            'description' => 'Used by delivery zone tests.',
            'price' => 5000,
            'stock_quantity' => 50,
            'status' => 'active',
            'unit' => 'bag',
        ]);
    }

    private function checkoutPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Zone Tester',
            'email' => 'zonetester@example.test',
            'phone' => '08031234567',
            'delivery_option' => 'delivery',
            'payment_option' => 'paystack',
            'address' => '15 Old Nayara Road, Mararaba',
            'preferred_delivery_date' => now()->addDays(2)->toDateString(),
        ], $overrides);
    }

    /* ------------------------------------------------------------------
     | P14/15 — the admin settings screen
     * ---------------------------------------------------------------- */

    public function test_delivery_settings_are_admin_only(): void
    {
        $this->seed(DeliveryZoneSeeder::class);

        $this->get(route('admin.delivery-settings.index'))->assertRedirect(route('login'));

        $this->actingAs($this->staff(User::ROLE_SALES))
            ->get(route('admin.delivery-settings.index'))
            ->assertForbidden();

        $this->actingAs($this->staff(User::ROLE_INVENTORY))
            ->get(route('admin.delivery-settings.index'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('admin.delivery-settings.index'))
            ->assertOk()
            ->assertSee('Zone 1');
    }

    public function test_the_sidebar_link_only_shows_for_admins(): void
    {
        $link = 'href="'.route('admin.delivery-settings.index').'"';

        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertSee($link, false);
        $this->actingAs($this->staff(User::ROLE_SALES))->get(route('admin.dashboard'))->assertDontSee($link, false);
    }

    public function test_a_zone_can_be_created_updated_and_is_audited(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.delivery-settings.zones.store'), [
                'name' => 'Zone 11',
                'fee' => 25000,
                'status' => 'active',
            ])
            ->assertSessionHas('status');

        $zone = DeliveryZone::where('name', 'Zone 11')->firstOrFail();
        $this->assertSame(25000.0, (float) $zone->fee);

        $this->actingAs($admin)
            ->patch(route('admin.delivery-settings.zones.update', $zone), [
                'name' => 'Zone 11',
                'fee' => 26000,
                'status' => 'active',
            ])
            ->assertSessionHas('status');

        $this->assertSame(26000.0, (float) $zone->fresh()->fee);

        $this->assertDatabaseHas('activity_logs', ['action' => 'delivery.settings_updated']);
    }

    public function test_a_zone_with_mapped_areas_cannot_be_deleted(): void
    {
        $this->seed(DeliveryZoneSeeder::class);
        $admin = $this->admin();
        $zone = DeliveryZone::where('name', 'Zone 1')->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('admin.delivery-settings.zones.destroy', $zone))
            ->assertSessionHas('error');

        $this->assertNotNull($zone->fresh());

        // Free the areas, then it can go.
        DeliveryArea::where('delivery_zone_id', $zone->id)->update(['delivery_zone_id' => null]);

        $this->actingAs($admin)
            ->delete(route('admin.delivery-settings.zones.destroy', $zone))
            ->assertSessionHas('status');

        $this->assertNull($zone->fresh());
    }

    public function test_a_state_with_areas_cannot_be_deleted(): void
    {
        $this->seed(DeliveryZoneSeeder::class);
        $admin = $this->admin();
        $state = DeliveryState::where('name', 'Nasarawa')->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('admin.delivery-settings.states.destroy', $state))
            ->assertSessionHas('error');

        $this->assertNotNull($state->fresh());
    }

    public function test_an_area_can_be_mapped_to_a_zone(): void
    {
        $this->seed(DeliveryZoneSeeder::class);
        $admin = $this->admin();
        $state = DeliveryState::where('name', 'Nasarawa')->firstOrFail();
        $zone3 = DeliveryZone::where('name', 'Zone 3')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.delivery-settings.areas.store'), [
                'delivery_state_id' => $state->id,
                'name' => 'Akwanga North',
                'status' => 'active',
                'delivery_zone_id' => $zone3->id,
            ])
            ->assertSessionHas('status');

        $area = DeliveryArea::where('name', 'Akwanga North')->firstOrFail();
        $this->assertSame($zone3->id, (int) $area->delivery_zone_id);
        $this->assertSame($state->id, (int) $area->delivery_state_id);
    }

    public function test_duplicate_areas_in_the_same_state_are_refused(): void
    {
        $this->seed(DeliveryZoneSeeder::class);
        $state = DeliveryState::where('name', 'Nasarawa')->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('admin.delivery-settings.areas.store'), [
                'delivery_state_id' => $state->id,
                'name' => 'Mararaba',
                'status' => 'active',
                'delivery_zone_id' => null,
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, DeliveryArea::where('name', 'Mararaba')->count());
    }

    /* ------------------------------------------------------------------
     | P16/17 — checkout pricing (server side)
     * ---------------------------------------------------------------- */

    public function test_a_pickup_order_is_free_and_carries_no_location(): void
    {
        $this->fakePaystack();
        $this->seed(DeliveryZoneSeeder::class);
        $product = $this->product();

        $this->actingAs($this->buyer())
            ->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload([
                'delivery_option' => 'pickup',
                'address' => '',
                'preferred_delivery_date' => '',
                'delivery_state_id' => '',
                'delivery_area_id' => '',
            ]))
            ->assertRedirect();

        $order = Order::firstOrFail();
        $this->assertSame(0.0, (float) $order->delivery_fee);
        $this->assertSame('not_applicable', $order->delivery_fee_status);
        $this->assertNull($order->delivery_state_name);
        $this->assertSame((float) $order->subtotal, (float) $order->total);
    }

    public function test_a_delivery_order_pays_the_zone_fee_when_the_area_is_mapped(): void
    {
        $this->fakePaystack();
        $this->seed(DeliveryZoneSeeder::class);
        $product = $this->product();

        $state = DeliveryState::where('name', 'Nasarawa')->firstOrFail();
        $area = DeliveryArea::where('name', 'Mararaba')->firstOrFail();

        $this->actingAs($this->buyer())
            ->withSession(['cart' => [$product->id => 2]])
            ->post(route('checkout.store'), $this->checkoutPayload([
                'delivery_state_id' => $state->id,
                'delivery_area_id' => $area->id,
            ]))
            ->assertRedirect();

        $order = Order::firstOrFail();
        $this->assertSame(5000.0, (float) $order->delivery_fee);
        $this->assertSame('confirmed', $order->delivery_fee_status);
        $this->assertSame('zone', $order->delivery_fee_source);
        $this->assertSame('Zone 1', $order->delivery_zone_name);
        $this->assertSame('Mararaba', $order->delivery_area_name);
        $this->assertSame('Nasarawa', $order->delivery_state_name);
        $this->assertSame((float) $order->subtotal + 5000.0, (float) $order->total);
        $this->assertSame('Mararaba, Nasarawa (Zone 1)', $order->deliveryLocationLabel());
    }

    public function test_a_delivery_order_without_an_area_pays_the_state_fee(): void
    {
        $this->fakePaystack();
        $this->seed(DeliveryZoneSeeder::class);
        $product = $this->product();

        // Lagos is a non-zone state: the configured state-level fee applies
        // regardless of any area text (Final Spec §8 — price table).
        $state = DeliveryState::where('name', 'Lagos')->firstOrFail();

        $this->actingAs($this->buyer())
            ->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload([
                'delivery_state_id' => $state->id,
                'delivery_area_id' => '',
                'address' => '3 Awolowo Road, Ikoyi, Lagos',
            ]))
            ->assertRedirect();

        $order = Order::firstOrFail();
        $this->assertSame(55000.0, (float) $order->delivery_fee);
        $this->assertSame('state', $order->delivery_fee_source);
        $this->assertNull($order->delivery_zone_name);
        $this->assertSame('Lagos', $order->delivery_state_name);
    }

    public function test_an_inactive_state_blocks_the_checkout(): void
    {
        $this->seed(DeliveryZoneSeeder::class);
        $product = $this->product();

        $state = DeliveryState::where('name', 'Lagos')->firstOrFail();
        $state->update(['status' => DeliveryState::STATUS_INACTIVE]);

        $this->actingAs($this->buyer())
            ->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload([
                'delivery_state_id' => $state->id,
            ]))
            ->assertSessionHasErrors('delivery_state_id');

        $this->assertSame(0, Order::count());
    }

    public function test_an_area_from_another_state_is_rejected(): void
    {
        $this->seed(DeliveryZoneSeeder::class);
        $product = $this->product();

        // Zone states price through their own areas (§7/§13): an area that
        // belongs to a different state can never set this state's price.
        $state = DeliveryState::where('name', 'Federal Capital Territory')->firstOrFail();
        $mararaba = DeliveryArea::where('name', 'Mararaba')->firstOrFail(); // Nasarawa

        $this->actingAs($this->buyer())
            ->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload([
                'delivery_state_id' => $state->id,
                'delivery_area_id' => $mararaba->id,
                'address' => '4 Ademola Adetokunbo Way, Abuja',
            ]))
            ->assertSessionHasErrors([
                'delivery_area_id' => 'Delivery is currently unavailable for this area. Please contact Customer Care.',
            ]);

        $this->assertSame(0, Order::count());
    }

    public function test_a_state_without_any_fee_is_not_serviceable(): void
    {
        $this->seed(DeliveryZoneSeeder::class);
        $product = $this->product();

        $state = DeliveryState::create(['name' => 'Testland', 'status' => 'active', 'default_fee' => null]);

        $this->actingAs($this->buyer())
            ->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload([
                'delivery_state_id' => $state->id,
            ]))
            ->assertSessionHasErrors('delivery_state_id');

        $this->assertSame(0, Order::count());
    }

    /* ------------------------------------------------------------------
     | P18 — manual fee override
     * ---------------------------------------------------------------- */

    public function test_overriding_a_confirmed_fee_requires_a_reason_and_is_audited(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $customer = $this->buyer();

        $order = Order::create([
            'order_number' => 'HBM-2026-000777',
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '08031234567',
            'delivery_address' => '15 Old Nayara Road, Mararaba',
            'delivery_option' => 'delivery',
            'preferred_delivery_date' => now()->addDay()->toDateString(),
            'payment_option' => 'paystack',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 10000,
            'delivery_fee' => 5000,
            'delivery_fee_status' => 'confirmed',
            'delivery_fee_source' => 'zone',
            'total' => 15000,
        ]);

        // Without a reason — refused.
        $this->actingAs($admin)
            ->post(route('admin.orders.delivery-fee', $order), ['delivery_fee' => 8000])
            ->assertSessionHasErrors('override_reason');

        $this->assertSame(5000.0, (float) $order->fresh()->delivery_fee);

        // With a reason — audited, snapshotted as manual, customer notified.
        $this->actingAs($admin)
            ->post(route('admin.orders.delivery-fee', $order), [
                'delivery_fee' => 8000,
                'override_reason' => 'Agreed special rate for repeat customer.',
            ])
            ->assertSessionHas('status');

        $fresh = $order->fresh();
        $this->assertSame(8000.0, (float) $fresh->delivery_fee);
        $this->assertSame(18000.0, (float) $fresh->total);
        $this->assertSame('manual', $fresh->delivery_fee_source);
        $this->assertSame('Agreed special rate for repeat customer.', $fresh->delivery_fee_note);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'delivery.fee_overridden',
            'subject_id' => $order->id,
        ]);
        Notification::assertSentTo($customer, DeliveryFeeConfirmed::class);
    }

    public function test_a_historical_order_keeps_its_old_price_and_zone_after_admin_edits(): void
    {
        $this->fakePaystack();
        $this->seed(DeliveryZoneSeeder::class);
        $product = $this->product();
        $state = DeliveryState::where('name', 'Nasarawa')->firstOrFail();
        $area = DeliveryArea::where('name', 'Mararaba')->firstOrFail();

        $this->actingAs($this->buyer())
            ->withSession(['cart' => [$product->id => 1]])
            ->post(route('checkout.store'), $this->checkoutPayload([
                'delivery_state_id' => $state->id,
                'delivery_area_id' => $area->id,
            ]))
            ->assertRedirect();

        $order = Order::firstOrFail();

        // The admin retunes the zone and renames things afterwards.
        $zone = DeliveryZone::where('name', 'Zone 1')->firstOrFail();
        $zone->update(['fee' => 99000, 'name' => 'Zone 1 Renamed']);
        $area->update(['name' => 'Mararaba New']);

        $fresh = $order->fresh();
        $this->assertSame(5000.0, (float) $fresh->delivery_fee);
        $this->assertSame('Zone 1', $fresh->delivery_zone_name);
        $this->assertSame('Mararaba', $fresh->delivery_area_name);
        $this->assertSame('Mararaba, Nasarawa (Zone 1)', $fresh->deliveryLocationLabel());
    }
}

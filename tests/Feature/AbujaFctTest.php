<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryState;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\StateLabels;
use Database\Seeders\DeliveryZoneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Improvement — "Federal Capital Territory" is READ as "Abuja (FCT)"
 * everywhere, while the database keeps storing the original value.
 *
 * Nothing here renames a row: the stored name, the zone ids, the fees and
 * the order snapshots all stay exactly as they were.
 */
class AbujaFctTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------
     | Stored data is untouched
     * ---------------------------------------------------------------- */

    public function test_the_stored_state_name_stays_federal_capital_territory(): void
    {
        $this->seed(DeliveryZoneSeeder::class);

        $this->assertDatabaseHas('delivery_states', ['name' => 'Federal Capital Territory']);
        $this->assertDatabaseMissing('delivery_states', ['name' => 'Abuja (FCT)']);
        $this->assertSame(1, DeliveryState::where('name', 'Federal Capital Territory')->count());
    }

    public function test_seeding_twice_never_creates_a_duplicate_state(): void
    {
        $this->seed(DeliveryZoneSeeder::class);
        $afterFirst = DeliveryState::count();

        $this->seed(DeliveryZoneSeeder::class);

        $this->assertSame($afterFirst, DeliveryState::count());
        $this->assertSame(1, DeliveryState::where('name', 'Federal Capital Territory')->count());
        $this->assertSame(0, DeliveryState::where('name', 'Abuja (FCT)')->count());
    }

    public function test_the_delivery_zones_and_their_fees_are_untouched(): void
    {
        $this->seed(DeliveryZoneSeeder::class);

        $this->assertSame(10, DeliveryZone::count());

        $expected = [1 => 5000, 2 => 7000, 3 => 9000, 4 => 12000, 5 => 15000,
            6 => 7000, 7 => 10000, 8 => 13000, 9 => 16000, 10 => 20000];

        foreach ($expected as $number => $fee) {
            $zone = DeliveryZone::where('zone_number', $number)->firstOrFail();
            $this->assertSame('Zone '.$number, $zone->name);
            $this->assertSame($fee, (int) $zone->fee);
        }

        // Nasarawa and every other state keep their own configuration.
        $nasarawa = DeliveryState::where('name', 'Nasarawa')->firstOrFail();
        $this->assertSame(20000.0, (float) $nasarawa->default_fee);

        $lagos = DeliveryState::where('name', 'Lagos')->firstOrFail();
        $this->assertSame(55000.0, (float) $lagos->default_fee);
    }

    /* ------------------------------------------------------------------
     | Display mapping
     * ---------------------------------------------------------------- */

    public function test_the_public_label_is_abuja_fct_and_everything_else_is_unchanged(): void
    {
        $this->assertSame('Abuja (FCT)', StateLabels::label('Federal Capital Territory'));
        $this->assertSame('Nasarawa', StateLabels::label('Nasarawa'));
        $this->assertSame('Lagos', StateLabels::label('Lagos'));
        $this->assertSame('', StateLabels::label(''));
        $this->assertNull(StateLabels::label(null));

        // A label that only differs by the display rename never counts as a
        // genuinely different value …
        $this->assertTrue(StateLabels::sameValue('Federal Capital Territory', 'Abuja (FCT)'));
        $this->assertTrue(StateLabels::sameValue('Federal Capital Territory', 'Federal Capital Territory'));
        // … anything else is a real rename.
        $this->assertFalse(StateLabels::sameValue('Federal Capital Territory', 'Lagos'));

        // A search typed with the public label still finds the stored row.
        $this->assertSame(['Federal Capital Territory'], StateLabels::storedNamesMatching('Abuja'));
        $this->assertSame([], StateLabels::storedNamesMatching('Nasarawa'));
    }

    public function test_the_checkout_state_dropdown_shows_abuja_fct(): void
    {
        $this->seed(DeliveryZoneSeeder::class);

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'is_active' => true]);
        $product = $this->product();

        $this->actingAs($customer)
            ->post(route('cart.store'), ['product_id' => $product->id, 'qty' => 1]);

        $this->actingAs($customer)
            ->get(route('checkout.show'))
            ->assertOk()
            ->assertSee('Abuja (FCT)')
            ->assertDontSee('Federal Capital Territory');
    }

    public function test_the_admin_delivery_settings_show_abuja_fct(): void
    {
        $this->seed(DeliveryZoneSeeder::class);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        $this->actingAs($admin)
            ->get(route('admin.delivery-settings.index'))
            ->assertOk()
            ->assertSee('Abuja (FCT)')
            ->assertDontSee('Federal Capital Territory');
    }

    public function test_an_order_reads_abuja_fct_but_still_stores_the_old_name(): void
    {
        $this->seed(DeliveryZoneSeeder::class);

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'is_active' => true]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $state = DeliveryState::where('name', 'Federal Capital Territory')->firstOrFail();

        $order = Order::create([
            'order_number' => 'HBM-2026-000777',
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '08031234567',
            'delivery_address' => '11 Nyanya Road, Abuja',
            'delivery_option' => 'delivery',
            'preferred_delivery_date' => now()->addDay()->toDateString(),
            'payment_option' => 'pay_on_delivery',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 4000,
            'delivery_fee' => 5000,
            'total' => 9000,
            'delivery_state_id' => $state->id,
            'delivery_state_name' => $state->name,
            'delivery_area_name' => 'Nyanya',
            'delivery_zone_name' => 'Zone 1',
        ]);

        // The snapshot is still the stored value …
        $this->assertSame('Federal Capital Territory', $order->delivery_state_name);
        // … only what people read changes.
        $this->assertSame('Nyanya, Abuja (FCT) (Zone 1)', $order->deliveryLocationLabel());

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('Abuja (FCT)')
            ->assertDontSee('Federal Capital Territory');
    }

    public function test_saving_the_state_row_with_the_public_label_keeps_the_stored_value(): void
    {
        $this->seed(DeliveryZoneSeeder::class);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $state = DeliveryState::where('name', 'Federal Capital Territory')->firstOrFail();

        // The admin form is pre-filled with "Abuja (FCT)" — saving the row
        // that way (or changing only its fee) must not rewrite the name.
        $this->actingAs($admin)
            ->patch(route('admin.delivery-settings.states.update', $state), [
                'name' => 'Abuja (FCT)',
                'default_fee' => $state->default_fee,
                'status' => $state->status,
            ])
            ->assertSessionHas('error');

        $this->assertSame('Federal Capital Territory', $state->fresh()->name);

        $this->actingAs($admin)
            ->patch(route('admin.delivery-settings.states.update', $state), [
                'name' => 'Abuja (FCT)',
                'default_fee' => 12345,
                'status' => $state->status,
            ])
            ->assertSessionHas('status');

        $state->refresh();
        $this->assertSame('Federal Capital Territory', $state->name);
        $this->assertSame(12345.0, (float) $state->default_fee);
        $this->assertSame(1, DeliveryState::where('name', 'Federal Capital Territory')->count());
    }

    /* ------------------------------------------------------------------
     | Helpers
     * ---------------------------------------------------------------- */

    private function product(): Product
    {
        return Product::create([
            'category_id' => Category::firstOrCreate(
                ['slug' => 'building-materials'],
                ['name' => 'Building Materials']
            )->id,
            'name' => 'Abuja Display Cement',
            'slug' => 'abuja-display-cement-'.uniqid(),
            'description' => 'Used by the Abuja display-name checks.',
            'price' => 3000,
            'stock_quantity' => 20,
            'status' => 'active',
            'unit' => 'bag',
        ]);
    }
}

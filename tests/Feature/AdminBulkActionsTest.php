<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BusinessContact;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §39 bulk actions on customer-care contacts and user accounts, the
 * per-row contact delete, and stock edits made on the product screen
 * being recorded on the Stock Adjustments history (§36).
 */
class AdminBulkActionsTest extends TestCase
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

    private function contact(array $attrs = []): BusinessContact
    {
        static $i = 0;
        $i++;

        return BusinessContact::create(array_merge([
            'label' => 'Contact '.$i,
            'phone' => '0803-000-00'.str_pad((string) $i, 2, '0'),
            'status' => 'active',
            'contact_order' => $i,
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
            'name' => 'Bulk Item '.$i,
            'slug' => 'bulk-item-'.$i.'-'.uniqid(),
            'description' => 'Used by the bulk-action tests.',
            'price' => 5000,
            'stock_quantity' => 0,
            'status' => 'active',
            'unit' => 'bag',
        ], $attrs));
    }

    /* ------------------------------------------------------------------
     | Customer-care contacts — per-row delete
     * ---------------------------------------------------------------- */

    public function test_a_contact_can_be_deleted_and_the_row_is_gone_for_good(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $contact = $this->contact(['label' => 'Old Hotline']);

        $this->actingAs($admin)
            ->delete(route('admin.contacts.destroy', $contact))
            ->assertRedirect(route('admin.contacts.index'))
            ->assertSessionHas('status');

        $this->assertNull(BusinessContact::find($contact->id));

        $log = ActivityLog::where('action', 'contact.deleted')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('Old Hotline', $log->subject_label);
    }

    public function test_contact_routes_are_admin_only(): void
    {
        $contact = $this->contact();

        $this->get(route('admin.contacts.index'))->assertRedirect(route('login'));

        $sales = $this->staff(User::ROLE_SALES);
        $this->actingAs($sales)
            ->delete(route('admin.contacts.destroy', $contact))
            ->assertForbidden();

        $this->assertNotNull(BusinessContact::find($contact->id));
    }

    /* ------------------------------------------------------------------
     | Customer-care contacts — bulk actions
     * ---------------------------------------------------------------- */

    public function test_contacts_can_be_bulk_deactivated_and_activated_again(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $a = $this->contact(['label' => 'Care Line']);
        $b = $this->contact(['label' => 'Sales Line']);

        $this->actingAs($admin)
            ->post(route('admin.contacts.bulk'), [
                'action' => 'deactivate',
                'contact_ids' => [$a->id, $b->id],
            ])
            ->assertSessionHas('status');

        $this->assertSame('inactive', $a->fresh()->status);
        $this->assertSame('inactive', $b->fresh()->status);

        $log = ActivityLog::where('action', 'contacts.bulk_deactivate')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(2, (int) $log->details['count']);

        // Reactivate them in one press.
        $this->actingAs($admin)
            ->post(route('admin.contacts.bulk'), [
                'action' => 'activate',
                'contact_ids' => [$a->id, $b->id],
            ])
            ->assertSessionHas('status');

        $this->assertSame('active', $a->fresh()->status);
        $this->assertSame('active', $b->fresh()->status);
        $this->assertNotNull(
            ActivityLog::where('action', 'contacts.bulk_activate')->latest('id')->first()
        );
    }

    public function test_contacts_can_be_bulk_deleted(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $keep = $this->contact(['label' => 'Keep Me']);
        $a = $this->contact(['label' => 'Retire 1']);
        $b = $this->contact(['label' => 'Retire 2']);

        $this->actingAs($admin)
            ->post(route('admin.contacts.bulk'), [
                'action' => 'delete',
                'contact_ids' => [$a->id, $b->id],
            ])
            ->assertSessionHas('status');

        $this->assertNull(BusinessContact::find($a->id));
        $this->assertNull(BusinessContact::find($b->id));
        $this->assertNotNull(BusinessContact::find($keep->id));

        $log = ActivityLog::where('action', 'contacts.bulk_delete')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(2, (int) $log->details['count']);
    }

    public function test_bulk_contact_action_validates_its_input(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->post(route('admin.contacts.bulk'), ['action' => 'delete'])
            ->assertSessionHasErrors('contact_ids');

        $this->actingAs($admin)
            ->post(route('admin.contacts.bulk'), [
                'action' => 'explode',
                'contact_ids' => [1],
            ])
            ->assertSessionHasErrors('action');
    }

    /* ------------------------------------------------------------------
     | Users & staff — bulk actions
     * ---------------------------------------------------------------- */

    public function test_accounts_can_be_bulk_deactivated_and_activated_again(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $one = $this->staff(User::ROLE_SALES);
        $two = $this->staff(User::ROLE_INVENTORY);

        $this->actingAs($admin)
            ->post(route('admin.users.bulk'), [
                'action' => 'deactivate',
                'user_ids' => [$one->id, $two->id],
            ])
            ->assertSessionHas('status');

        $this->assertFalse((bool) $one->fresh()->is_active);
        $this->assertFalse((bool) $two->fresh()->is_active);

        $log = ActivityLog::where('action', 'users.bulk_deactivate')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(2, (int) $log->details['count']);

        $this->actingAs($admin)
            ->post(route('admin.users.bulk'), [
                'action' => 'activate',
                'user_ids' => [$one->id, $two->id],
            ])
            ->assertSessionHas('status');

        $this->assertTrue((bool) $one->fresh()->is_active);
        $this->assertTrue((bool) $two->fresh()->is_active);
    }

    public function test_bulk_deactivate_never_locks_the_admin_out_of_their_own_account(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $other = $this->staff(User::ROLE_SALES);

        $this->actingAs($admin)
            ->post(route('admin.users.bulk'), [
                'action' => 'deactivate',
                'user_ids' => [$admin->id, $other->id],
            ])
            ->assertSessionHas('status');

        $this->assertTrue((bool) $admin->fresh()->is_active);
        $this->assertFalse((bool) $other->fresh()->is_active);
    }

    public function test_the_users_bulk_route_is_admin_only(): void
    {
        $sales = $this->staff(User::ROLE_SALES);
        $target = $this->staff(User::ROLE_INVENTORY);

        $this->post(route('admin.users.bulk'), [
            'action' => 'deactivate',
            'user_ids' => [$target->id],
        ])->assertRedirect(route('login'));

        $this->actingAs($sales)
            ->post(route('admin.users.bulk'), [
                'action' => 'deactivate',
                'user_ids' => [$target->id],
            ])
            ->assertForbidden();

        $this->assertTrue((bool) $target->fresh()->is_active);
    }

    /* ------------------------------------------------------------------
     | Stock edits on the product screen land in the adjustment history
     * ---------------------------------------------------------------- */

    public function test_editing_stock_on_the_product_screen_records_an_adjustment(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product(['stock_quantity' => 0]);

        $this->actingAs($admin)
            ->put(route('admin.products.update', $product), [
                'category_id' => $product->category_id,
                'name' => $product->name,
                'price' => 5000,
                'stock_quantity' => 100,
                'status' => 'active',
                'unit' => 'bag',
            ])
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('status');

        $this->assertSame(100, (int) $product->fresh()->stock_quantity);

        $adjustment = $product->adjustments()->first();
        $this->assertNotNull($adjustment);
        $this->assertSame(0, (int) $adjustment->previous_quantity);
        $this->assertSame(100, (int) $adjustment->new_quantity);
        $this->assertSame(100, (int) $adjustment->difference);
        $this->assertSame('Stock edited from the product screen', $adjustment->reason);
        $this->assertSame($admin->id, (int) $adjustment->adjusted_by);

        // The Stock Adjustments screen shows it.
        $this->actingAs($admin)
            ->get(route('admin.stock-adjustments.index'))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee('Stock edited from the product screen');

        // …and the audit trail mentions it.
        $log = ActivityLog::where('action', 'stock.adjusted')
            ->where('subject_id', $product->id)
            ->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('from 0 to 100', $log->description);
    }

    public function test_saving_the_product_without_changing_stock_records_nothing(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $product = $this->product(['stock_quantity' => 40]);

        $this->actingAs($admin)
            ->put(route('admin.products.update', $product), [
                'category_id' => $product->category_id,
                'name' => $product->name,
                'price' => 5000,
                'stock_quantity' => 40,
                'status' => 'active',
                'unit' => 'bag',
            ])
            ->assertSessionHas('status');

        $this->assertSame(0, $product->adjustments()->count());
    }

    public function test_a_product_created_with_stock_gets_its_initial_adjustment_row(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->post(route('admin.products.store'), [
                'category_id' => Category::firstOrCreate(
                    ['slug' => 'initial-stock-cat'],
                    ['name' => 'Initial Stock Category']
                )->id,
                'name' => 'Pre-Stocked Item',
                'price' => 4500,
                'stock_quantity' => 30,
                'status' => 'active',
                'unit' => 'bag',
            ])
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('status');

        $product = Product::where('name', 'Pre-Stocked Item')->firstOrFail();

        $adjustment = $product->adjustments()->first();
        $this->assertNotNull($adjustment);
        $this->assertSame(0, (int) $adjustment->previous_quantity);
        $this->assertSame(30, (int) $adjustment->new_quantity);
        $this->assertSame('Initial stock when the product was created', $adjustment->reason);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * PHASE 1 — deactivation enforcement.
 *
 * Deactivating an account must stop it working everywhere, immediately:
 * an existing session is terminated on the next request (not just at the
 * next login), every role-guarded screen refuses it, and the system can
 * never be left without an accessible administrator.
 */
class DeactivationEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------
     | Helpers
     * ---------------------------------------------------------------- */

    private function staff(string $role, bool $active = true): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => $active]);
    }

    /** Flip the flag in the database and on the in-memory instance. */
    private function deactivate(User $user): User
    {
        User::whereKey($user->id)->update(['is_active' => false]);

        return $user->refresh();
    }

    private function reactivate(User $user): User
    {
        User::whereKey($user->id)->update(['is_active' => true]);

        return $user->refresh();
    }

    /* ------------------------------------------------------------------
     | An existing session is terminated immediately
     * ---------------------------------------------------------------- */

    public function test_a_deactivated_staff_member_loses_their_session_on_the_next_request(): void
    {
        $sales = $this->staff(User::ROLE_SALES);

        // The session is live and works.
        $this->actingAs($sales)->get(route('admin.orders.index'))->assertOk();

        // An admin deactivates them while they are still browsing.
        $this->deactivate($sales);

        // The very next request is bounced to login — the session is dead.
        $this->actingAs($sales)
            ->get(route('admin.orders.index'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_deactivated_customer_loses_their_session_on_the_next_request(): void
    {
        $customer = User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
        ]);

        $this->actingAs($customer)->get(route('orders.index'))->assertOk();

        $this->deactivate($customer);

        $this->actingAs($customer)
            ->get(route('orders.index'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_session_termination_explains_itself(): void
    {
        $sales = $this->staff(User::ROLE_SALES);
        $this->deactivate($sales);

        $this->actingAs($sales)
            ->get(route('admin.orders.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
    }

    /* ------------------------------------------------------------------
     | Login is refused
     * ---------------------------------------------------------------- */

    public function test_a_deactivated_staff_member_cannot_log_in(): void
    {
        $sales = $this->staff(User::ROLE_SALES, active: false);

        $this->post(route('login'), [
            'email' => $sales->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_deactivated_customer_cannot_log_in(): void
    {
        $customer = User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'is_active' => false,
        ]);

        $this->post(route('login'), [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_direct_url_access_is_refused_even_with_a_live_session(): void
    {
        $inventory = $this->staff(User::ROLE_INVENTORY);

        $this->actingAs($inventory)->get(route('admin.products.index'))->assertOk();

        $this->deactivate($inventory);

        // A bookmarked deep link is refused just as firmly as the board.
        $this->actingAs($inventory)
            ->get(route('admin.products.index'))
            ->assertRedirect(route('login'));
    }

    /* ------------------------------------------------------------------
     | Reactivation restores access — nothing else changes
     * ---------------------------------------------------------------- */

    public function test_reactivating_the_account_restores_access_without_other_changes(): void
    {
        $sales = $this->staff(User::ROLE_SALES);

        $this->deactivate($sales);
        $this->actingAs($sales)->get(route('admin.orders.index'))->assertRedirect(route('login'));

        $this->reactivate($sales);

        // Same role, same account — it simply works again.
        $this->assertSame(User::ROLE_SALES, $sales->role);
        $this->actingAs($sales)->get(route('admin.orders.index'))->assertOk();
    }

    /* ------------------------------------------------------------------
     | The system is never left without an accessible administrator
     * ---------------------------------------------------------------- */

    public function test_the_last_admin_cannot_be_demoted(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => User::ROLE_SALES,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(User::ROLE_ADMIN, $admin->fresh()->role);
    }

    public function test_the_last_admin_cannot_be_deactivated(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => User::ROLE_ADMIN,
                'is_active' => false,
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_an_admin_can_be_demoted_once_another_active_admin_exists(): void
    {
        $first = $this->staff(User::ROLE_ADMIN);
        $second = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($first)
            ->put(route('admin.users.update', $second), [
                'name' => $second->name,
                'email' => $second->email,
                'role' => User::ROLE_SALES,
                'is_active' => true,
            ])
            ->assertSessionHas('status');

        $this->assertSame(User::ROLE_SALES, $second->fresh()->role);
    }

    public function test_an_admin_cannot_be_deactivated_once_another_active_admin_exists(): void
    {
        $first = $this->staff(User::ROLE_ADMIN);
        $second = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($first)
            ->put(route('admin.users.update', $second), [
                'name' => $second->name,
                'email' => $second->email,
                'role' => User::ROLE_ADMIN,
                'is_active' => false,
            ])
            ->assertSessionHas('status');

        $this->assertFalse($second->fresh()->is_active);
    }

    /* ------------------------------------------------------------------
     | An admin can never lock themselves out
     * ---------------------------------------------------------------- */

    public function test_an_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => User::ROLE_ADMIN,
                'is_active' => false,
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_an_admin_cannot_demote_their_own_admin_role(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => User::ROLE_SALES,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(User::ROLE_ADMIN, $admin->fresh()->role);
    }

    /* ------------------------------------------------------------------
     | Bulk deactivation obeys the same rules
     * ---------------------------------------------------------------- */

    public function test_bulk_deactivation_never_locks_the_acting_admin_out(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $other = $this->staff(User::ROLE_ADMIN);
        $sales = $this->staff(User::ROLE_SALES);

        $this->actingAs($admin)
            ->post(route('admin.users.bulk'), [
                'action' => 'deactivate',
                'user_ids' => [$admin->id, $other->id, $sales->id],
            ])
            ->assertSessionHas('status');

        // The acting admin is still active; the others were deactivated.
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertFalse($other->fresh()->is_active);
        $this->assertFalse($sales->fresh()->is_active);
    }

    public function test_bulk_deactivation_which_would_leave_no_admin_is_refused_for_the_last_admin(): void
    {
        // A lone admin selecting only themselves in bulk is skipped by the
        // self-exclusion, so the account list can never go admin-less.
        $admin = $this->staff(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->post(route('admin.users.bulk'), [
                'action' => 'deactivate',
                'user_ids' => [$admin->id],
            ])
            ->assertSessionHas('status');

        $this->assertTrue($admin->fresh()->is_active);
    }

    /* ------------------------------------------------------------------
     | Audit trail
     * ---------------------------------------------------------------- */

    public function test_deactivation_from_the_customer_directory_is_audited(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'is_active' => true]);

        $this->actingAs($admin)
            ->patch(route('admin.customers.status', $customer), ['active' => false])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.deactivated',
            'user_id' => $admin->id,
        ]);
    }

    public function test_reactivation_from_the_customer_directory_is_audited(): void
    {
        Notification::fake();
        $admin = $this->staff(User::ROLE_ADMIN);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'is_active' => false]);

        $this->actingAs($admin)
            ->patch(route('admin.customers.status', $customer), ['active' => true])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.activated',
            'user_id' => $admin->id,
        ]);
    }
}

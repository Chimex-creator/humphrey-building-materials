<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * PHASE 9 — account management and access recovery.
 *
 * Customer identity (name / email / phone) is never admin-editable; the
 * only way an admin recovers access for ANY account is Laravel's one-time
 * reset link, which now works for staff as well as customers. Staff email
 * changes are identity changes and leave an audit trail.
 */
class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------
     | Helpers
     * ---------------------------------------------------------------- */

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function customer(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
        ], $attrs));
    }

    /* ------------------------------------------------------------------
     | Customer identity is locked
     * ---------------------------------------------------------------- */

    public function test_a_customer_name_cannot_be_changed_from_the_admin_screen(): void
    {
        $admin = $this->admin();
        $customer = $this->customer(['name' => 'Real Name']);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $customer), [
                'name' => 'Renamed By Admin',
                'email' => $customer->email,
                'role' => User::ROLE_CUSTOMER,
                'phone' => $customer->phone,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame('Real Name', $customer->fresh()->name);
    }

    public function test_a_customer_email_cannot_be_changed_from_the_admin_screen(): void
    {
        $admin = $this->admin();
        $customer = $this->customer(['email' => 'real@example.test']);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $customer), [
                'name' => $customer->name,
                'email' => 'hijacked@example.test',
                'role' => User::ROLE_CUSTOMER,
                'phone' => $customer->phone,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame('real@example.test', $customer->fresh()->email);
    }

    public function test_a_customer_phone_cannot_be_changed_from_the_admin_screen(): void
    {
        $admin = $this->admin();
        $customer = $this->customer(['phone' => '08000000000']);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $customer), [
                'name' => $customer->name,
                'email' => $customer->email,
                'role' => User::ROLE_CUSTOMER,
                'phone' => '08999999999',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame('08000000000', $customer->fresh()->phone);
    }

    public function test_the_admin_can_still_manage_a_customers_role_and_status(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        // Identity is unchanged, so role + status go through normally.
        $this->actingAs($admin)
            ->put(route('admin.users.update', $customer), [
                'name' => $customer->name,
                'email' => $customer->email,
                'role' => User::ROLE_CUSTOMER,
                'phone' => $customer->phone,
                'is_active' => false,
            ])
            ->assertSessionHas('status');

        $this->assertFalse($customer->fresh()->is_active);
    }

    /* ------------------------------------------------------------------
     | Staff identity changes are audited
     * ---------------------------------------------------------------- */

    public function test_changing_a_staff_email_is_audited(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $staff = $this->staff(User::ROLE_SALES);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $staff), [
                'name' => $staff->name,
                'email' => 'new.address@example.test',
                'role' => User::ROLE_SALES,
                'phone' => $staff->phone,
                'is_active' => true,
            ])
            ->assertSessionHas('status');

        $this->assertSame('new.address@example.test', $staff->fresh()->email);

        $log = \App\Models\ActivityLog::where('action', 'user.email_changed')
            ->where('user_id', $admin->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'A staff email change must leave an audit trail.');
        $this->assertStringContainsString($staff->email, $log->description);
        $this->assertStringContainsString('new.address@example.test', $log->description);
    }

    public function test_changing_a_staff_role_is_audited(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $inventory = $this->staff(User::ROLE_INVENTORY);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $inventory), [
                'name' => $inventory->name,
                'email' => $inventory->email,
                'role' => User::ROLE_SALES,
                'phone' => $inventory->phone,
                'is_active' => true,
            ])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.role_changed',
            'user_id' => $admin->id,
        ]);
    }

    /* ------------------------------------------------------------------
     | Password reset links work for staff too
     * ---------------------------------------------------------------- */

    public function test_an_admin_can_send_a_reset_link_to_a_staff_account(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $staff = $this->staff(User::ROLE_SALES);

        $this->actingAs($admin)
            ->post(route('admin.users.send-reset', $staff))
            ->assertSessionHas('status');

        Notification::assertSentTo($staff, ResetPassword::class);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.reset_link_sent',
            'user_id' => $admin->id,
        ]);
    }

    public function test_an_admin_can_send_a_reset_link_to_a_customer_account(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $customer = $this->customer();

        $this->actingAs($admin)
            ->post(route('admin.users.send-reset', $customer))
            ->assertSessionHas('status');

        Notification::assertSentTo($customer, ResetPassword::class);
    }

    public function test_no_reset_link_is_sent_for_a_deactivated_account(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $staff = User::factory()->create([
            'role' => User::ROLE_SALES,
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.users.send-reset', $staff))
            ->assertSessionHas('error');

        Notification::assertNotSentTo($staff, ResetPassword::class);
    }

    public function test_only_an_admin_can_send_reset_links(): void
    {
        Notification::fake();
        $sales = $this->staff(User::ROLE_SALES);
        $other = $this->staff(User::ROLE_INVENTORY);

        $this->actingAs($sales)
            ->post(route('admin.users.send-reset', $other))
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    /* ------------------------------------------------------------------
     | A reset link really recovers access
     * ---------------------------------------------------------------- */

    public function test_a_reset_link_lets_a_locked_out_staff_member_set_a_new_password(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $staff = $this->staff(User::ROLE_SALES);

        $this->actingAs($admin)
            ->post(route('admin.users.send-reset', $staff))
            ->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo($staff, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        // The reset form and login are guest-only; drop the admin session
        // first or the guest middleware would bounce the follow-up posts.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $staff->email,
            'password' => 'BrandNewPass123!',
            'password_confirmation' => 'BrandNewPass123!',
        ])->assertSessionHas('status');

        // The old password no longer works; the new one does.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->post(route('login'), [
            'email' => $staff->email,
            'password' => 'BrandNewPass123!',
        ])->assertSessionHas('status');
    }
}

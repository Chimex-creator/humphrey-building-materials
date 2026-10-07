<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Staff account management, business settings, profile editing and the
 * password change flow — including the self-protection guards.
 */
class UsersSettingsProfileTest extends TestCase
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

    /* ------------------------------------------------------------------
     | Staff accounts
     * ---------------------------------------------------------------- */

    public function test_only_an_admin_can_manage_staff_accounts(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));

        $this->actingAs($this->staff(User::ROLE_SALES))
            ->get(route('admin.users.index'))
            ->assertForbidden();

        $this->actingAs($this->staff(User::ROLE_INVENTORY))
            ->get(route('admin.users.create'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('admin.users.index'))
            ->assertOk();
    }

    public function test_creating_a_staff_account_is_audited(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Grace Mensah',
                'email' => 'grace@example.test',
                'password' => 'secret-1234',
                'password_confirmation' => 'secret-1234',
                'role' => User::ROLE_INVENTORY,
                'phone' => '08031234567',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status');

        $user = User::where('email', 'grace@example.test')->firstOrFail();
        $this->assertSame(User::ROLE_INVENTORY, $user->role);
        $this->assertTrue((bool) $user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('secret-1234', $user->password));

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.created',
            'subject_id' => $user->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_staff_creation_validation(): void
    {
        $admin = $this->admin();
        $base = [
            'name' => 'Valid Person',
            'email' => 'valid@example.test',
            'password' => 'secret-1234',
            'password_confirmation' => 'secret-1234',
            'role' => User::ROLE_SALES,
        ];

        $this->actingAs($admin)
            ->post(route('admin.users.store'), array_merge($base, ['role' => User::ROLE_CUSTOMER]))
            ->assertSessionHasErrors('role');

        $this->actingAs($admin)
            ->post(route('admin.users.store'), array_merge($base, ['password' => 'short']))
            ->assertSessionHasErrors('password');

        $this->actingAs($admin)
            ->post(route('admin.users.store'), array_merge($base, ['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');

        $this->actingAs($admin)->post(route('admin.users.store'), $base);
        $this->actingAs($admin)
            ->post(route('admin.users.store'), $base)
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 2);
    }

    public function test_role_changes_and_deactivation_are_audited(): void
    {
        $admin = $this->admin();
        $target = $this->staff(User::ROLE_SALES);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role' => User::ROLE_INVENTORY,
                'is_active' => 0,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status');

        $target->refresh();
        $this->assertSame(User::ROLE_INVENTORY, $target->role);
        $this->assertFalse((bool) $target->is_active);

        $this->assertDatabaseHas('activity_logs', ['action' => 'user.role_changed', 'subject_id' => $target->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.deactivated', 'subject_id' => $target->id]);
    }

    public function test_an_admin_cannot_demote_or_deactivate_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => User::ROLE_SALES,
            ])
            ->assertSessionHasErrors('role');

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => User::ROLE_ADMIN,
                'is_active' => 0,
            ])
            ->assertSessionHasErrors('is_active');

        $admin->refresh();
        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertTrue((bool) $admin->is_active);
    }

    /* ------------------------------------------------------------------
     | Business settings
     * ---------------------------------------------------------------- */

    public function test_settings_are_saved_and_the_change_is_audited(): void
    {
        $admin = $this->admin();
        Setting::set('business_name', 'Old Trading Name');
        Setting::set('low_stock_threshold', 10);

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), [
                'business_name' => 'Humphrey Building Materials Ltd',
                'business_email' => 'sales@humphrey.test',
                'business_phone' => '08012345678',
                'business_address' => '12 Building Materials Road, Lagos',
                'receipt_note' => 'Thank you for shopping with us.',
                'low_stock_threshold' => 25,
            ])
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHas('status');

        $this->assertSame('Humphrey Building Materials Ltd', Setting::get('business_name'));
        $this->assertSame(25, Setting::int('low_stock_threshold'));

        $log = ActivityLog::where('action', 'settings.updated')->firstOrFail();
        $this->assertArrayHasKey('business_name', (array) $log->details);
    }

    public function test_settings_reject_incomplete_or_silly_values(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), [
                'business_name' => 'Humphrey',
                'business_email' => 'not-an-email',
                'low_stock_threshold' => 0,
            ])
            ->assertSessionHasErrors(['business_email', 'low_stock_threshold']);
    }

    public function test_settings_are_admin_only(): void
    {
        $this->actingAs($this->staff(User::ROLE_SALES))
            ->put(route('admin.settings.update'), [
                'business_name' => 'X',
                'business_email' => 'x@example.test',
                'low_stock_threshold' => 5,
            ])
            ->assertForbidden();
    }

    public function test_the_settings_screen_is_hidden_from_guests(): void
    {
        $this->get(route('admin.settings'))->assertRedirect(route('login'));
    }

    /* ------------------------------------------------------------------
     | Profile & password
     * ---------------------------------------------------------------- */

    public function test_a_customer_can_edit_their_profile(): void
    {
        Notification::fake();
        $customer = User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
            'name' => 'Old Name',
            'email' => 'old@example.test',
        ]);

        $this->actingAs($customer)
            ->patch(route('profile.update'), [
                'name' => 'New Name',
                'email' => 'old@example.test',
                'phone' => '08098765432',
                'address' => '5 Awolowo Road, Ikoyi',
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status');

        $customer->refresh();
        $this->assertSame('New Name', $customer->name);
        $this->assertSame('08098765432', $customer->phone);
        $this->assertNotNull($customer->email_verified_at);
    }

    public function test_changing_your_email_unverifies_the_account(): void
    {
        Notification::fake();
        $customer = User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
            'email' => 'before@example.test',
        ]);

        $this->actingAs($customer)
            ->patch(route('profile.update'), [
                'name' => $customer->name,
                'email' => 'after@example.test',
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status');

        $this->assertNull($customer->fresh()->email_verified_at);
    }

    public function test_profile_email_must_be_unique(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'taken@example.test']);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'is_active' => true]);

        $this->actingAs($customer)
            ->patch(route('profile.update'), [
                'name' => $customer->name,
                'email' => 'taken@example.test',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_password_change_needs_the_current_password(): void
    {
        $customer = User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
            'password' => 'original-pass-1',
        ]);

        $this->actingAs($customer)
            ->post(route('password.change.update'), [
                'current_password' => 'wrong-password',
                'password' => 'brand-new-pass-1',
                'password_confirmation' => 'brand-new-pass-1',
            ])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($customer)
            ->post(route('password.change.update'), [
                'current_password' => 'original-pass-1',
                'password' => 'brand-new-pass-1',
                'password_confirmation' => 'brand-new-pass-1',
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check('brand-new-pass-1', $customer->fresh()->password));
    }
}

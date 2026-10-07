<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The logout buttons ask "Are you sure you want to log out?" before
 * anything happens — an accidental tap must not end the session.
 */
class LogoutConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private const QUESTION = "confirm('Are you sure you want to log out?')";

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    public function test_the_storefront_logout_button_asks_for_confirmation(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);

        $this->actingAs($customer)
            ->get(route('home'))
            ->assertOk()
            ->assertSee(self::QUESTION, false);
    }

    public function test_the_admin_logout_button_asks_for_confirmation(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(self::QUESTION, false);
    }

    public function test_guests_never_see_a_logout_button_at_all(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee(self::QUESTION, false);
    }

    public function test_logout_still_works_when_the_user_confirms(): void
    {
        $customer = $this->user(User::ROLE_CUSTOMER);

        $this->actingAs($customer)
            ->post(route('logout'))
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }
}

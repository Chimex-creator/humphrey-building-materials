<?php

namespace Tests\Feature;

use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * PHASE 2 — role-appropriate welcome messages, shown once.
 *
 *  - first-time registration  → "Welcome, {name}!"
 *  - password login           → "Welcome back, {name}!"
 *  - repeat page visits       → the toast never repeats (flash semantics)
 */
class WelcomeMessageTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
        ]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    /* ------------------------------------------------------------------
     | First-time registration
     * ---------------------------------------------------------------- */

    public function test_completing_registration_welcomes_the_new_customer_by_name(): void
    {
        Notification::fake();

        $this->post(route('register'), [
            'name' => 'Miracle Paul',
            'email' => 'miracle@example.test',
            'phone' => '08031234567',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
        ]);

        $url = PendingRegistration::firstOrFail()->verificationUrl();

        $this->get($url)
            ->assertRedirect(route('home'))
            ->assertSessionHas('status', 'Welcome, Miracle Paul!');
    }

    /* ------------------------------------------------------------------
     | Password login
     * ---------------------------------------------------------------- */

    public function test_logging_in_welcomes_a_returning_customer_back_by_name(): void
    {
        $customer = $this->customer();

        $this->post(route('login'), [
            'email' => $customer->email,
            'password' => 'password',
        ])
            ->assertRedirect(route('home'))
            ->assertSessionHas('status', 'Welcome back, '.$customer->name.'!');
    }

    public function test_logging_in_welcomes_staff_to_the_admin_area(): void
    {
        $sales = $this->staff(User::ROLE_SALES);

        $this->post(route('login'), [
            'email' => $sales->email,
            'password' => 'password',
        ])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('status', 'Welcome back, '.$sales->name.'!');
    }

    /* ------------------------------------------------------------------
     | Shown once — never on refresh or a later revisit
     * ---------------------------------------------------------------- */

    public function test_the_login_welcome_is_shown_once_and_never_repeats(): void
    {
        $customer = $this->customer();

        $this->post(route('login'), [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertSessionHas('status');

        // First visit after login — the toast is on screen.
        $this->get(route('home'))->assertSee('Welcome back, '.$customer->name.'!');

        // A refresh (or any later visit) never shows it again.
        $this->get(route('home'))->assertDontSee('Welcome back, '.$customer->name.'!');
        $this->get(route('home'))->assertDontSee('Welcome back, '.$customer->name.'!');
    }

    public function test_the_registration_welcome_is_shown_once_and_never_repeats(): void
    {
        Notification::fake();

        $this->post(route('register'), [
            'name' => 'Miracle Paul',
            'email' => 'miracle@example.test',
            'phone' => '08031234567',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
        ]);

        $this->get(PendingRegistration::firstOrFail()->verificationUrl())
            ->assertRedirect(route('home'))
            ->assertSessionHas('status', 'Welcome, Miracle Paul!');

        // The redirect target shows it once…
        $this->get(route('home'))->assertSee('Welcome, Miracle Paul!');

        // …and a refresh never does.
        $this->get(route('home'))->assertDontSee('Welcome, Miracle Paul!');
    }

    /* ------------------------------------------------------------------
     | A deactivated account gets the refusal, never a welcome
     * ---------------------------------------------------------------- */

    public function test_a_deactivated_account_never_sees_a_welcome_message(): void
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
}

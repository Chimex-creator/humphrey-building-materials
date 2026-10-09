<?php

namespace Tests\Feature;

use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/**
 * "Continue with Google" — customers only (Laravel Socialite).
 *
 * The invariants that matter:
 *  - Google never changes anyone's role and never creates staff accounts.
 *  - Existing customers are linked, never duplicated.
 *  - Google-authenticated users are NEVER sent through the email/password
 *    verification flow — they arrive verified and logged in.
 *  - A leftover pending registration is never destroyed or duplicated.
 *  - Deactivated accounts stay out.
 */
class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Configured in tests so the button/routes are active; production
        // credentials come from GOOGLE_* environment variables only.
        config()->set('services.google.client_id', 'test-client-id');
        config()->set('services.google.client_secret', 'test-client-secret');
        config()->set('services.google.redirect', '/auth/google/callback');
    }

    private function googleAccount(string $email, string $id = 'g-123', string $name = 'Google Customer', bool $emailVerified = true): SocialiteUser
    {
        $user = new SocialiteUser;
        $user->map([
            'id' => $id,
            'email' => $email,
            'name' => $name,
            'avatar' => 'https://example.test/avatar.jpg',
            // Google's raw claims — the controller requires email_verified.
            'user' => ['id' => $id, 'email' => $email, 'email_verified' => $emailVerified],
        ]);

        return $user;
    }

    /* ------------------------------------------------------------------
     | Button + redirect
     * ---------------------------------------------------------------- */

    public function test_login_page_shows_the_google_button_only_when_configured(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Continue with Google');

        config()->set('services.google.client_id', null);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Continue with Google');
    }

    public function test_register_page_shows_the_google_button_only_when_configured(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Continue with Google');

        config()->set('services.google.client_id', null);

        $this->get(route('register'))
            ->assertOk()
            ->assertDontSee('Continue with Google');
    }

    public function test_redirect_sends_the_customer_to_google(): void
    {
        Socialite::fake('google');

        $this->get(route('google.redirect'))
            ->assertRedirect('https://socialite.fake/google/authorize');
    }

    public function test_redirect_explains_itself_when_google_is_not_configured(): void
    {
        config()->set('services.google.client_id', null);

        $this->get(route('google.redirect'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
    }

    /* ------------------------------------------------------------------
     | Callback — new customer
     * ---------------------------------------------------------------- */

    public function test_a_brand_new_google_customer_is_created_verified_and_logged_in(): void
    {
        Socialite::fake('google', $this->googleAccount('new.shopper@gmail.com', 'g-new', 'New Shopper'));

        // Straight to the shop — never the email-verification pages.
        $this->get(route('google.callback'))
            ->assertRedirect(route('home'));

        $this->assertAuthenticated();

        $user = User::where('email', 'new.shopper@gmail.com')->firstOrFail();
        $this->assertSame(User::ROLE_CUSTOMER, $user->role);
        $this->assertSame('g-new', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->is_active);
        $this->assertFalse(Hash::check('password', $user->password));
    }

    /* ------------------------------------------------------------------
     | Callback — existing accounts
     * ---------------------------------------------------------------- */

    public function test_existing_customer_is_linked_without_a_duplicate_and_keeps_role_and_password(): void
    {
        $customer = User::factory()->create([
            'name' => 'Existing Customer',
            'email' => 'existing.customer@gmail.com',
            'password' => 'secret-password',
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
        ]);

        Socialite::fake('google', $this->googleAccount('existing.customer@gmail.com', 'g-linked'));

        $this->get(route('google.callback'))
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($customer);
        $this->assertSame(1, User::where('email', 'existing.customer@gmail.com')->count());
        $this->assertSame(1, User::count());

        $fresh = $customer->fresh();
        $this->assertSame(User::ROLE_CUSTOMER, $fresh->role);
        $this->assertSame('g-linked', $fresh->google_id);
        $this->assertTrue(Hash::check('secret-password', $fresh->password));
    }

    public function test_unverified_existing_customer_is_verified_by_google_and_logged_in(): void
    {
        $customer = User::factory()->unverified()->create([
            'email' => 'unverified.customer@gmail.com',
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
        ]);

        Socialite::fake('google', $this->googleAccount('unverified.customer@gmail.com', 'g-unverified'));

        $this->get(route('google.callback'))
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($customer);
        $this->assertNotNull($customer->fresh()->email_verified_at);
    }

    /* ------------------------------------------------------------------
     | Callback — staff must never enter through Google
     * ---------------------------------------------------------------- */

    public function test_admin_accounts_are_refused_and_keep_their_role(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin.google@gmail.com',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        Socialite::fake('google', $this->googleAccount('admin.google@gmail.com', 'g-admin', 'Admin Person'));

        $this->get(route('google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();

        $fresh = $admin->fresh();
        $this->assertSame(User::ROLE_ADMIN, $fresh->role);
        $this->assertNull($fresh->google_id);
    }

    public function test_inventory_staff_accounts_are_refused_and_keep_their_role(): void
    {
        $staff = User::factory()->create([
            'email' => 'inventory.google@gmail.com',
            'role' => User::ROLE_INVENTORY,
            'is_active' => true,
        ]);

        Socialite::fake('google', $this->googleAccount('inventory.google@gmail.com', 'g-inv'));

        $this->get(route('google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();

        $fresh = $staff->fresh();
        $this->assertSame(User::ROLE_INVENTORY, $fresh->role);
        $this->assertNull($fresh->google_id);
    }

    public function test_deactivated_customers_are_refused(): void
    {
        User::factory()->create([
            'email' => 'banned.customer@gmail.com',
            'role' => User::ROLE_CUSTOMER,
            'is_active' => false,
        ]);

        Socialite::fake('google', $this->googleAccount('banned.customer@gmail.com', 'g-banned'));

        $this->get(route('google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
        $this->assertNull(User::where('email', 'banned.customer@gmail.com')->first()->google_id);
    }

    /* ------------------------------------------------------------------
     | Callback — Google must have verified the email itself
     * ---------------------------------------------------------------- */

    public function test_google_identities_without_email_verified_are_refused(): void
    {
        Socialite::fake('google', $this->googleAccount('unverified.claim@gmail.com', 'g-unver', 'Unverified Person', false));

        $this->get(route('google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'unverified.claim@gmail.com')->count());
    }

    /* ------------------------------------------------------------------
     | Callback — pending registration is never duplicated or destroyed
     * ---------------------------------------------------------------- */

    public function test_google_sign_in_proceeds_despite_a_pending_registration_and_leaves_it_untouched(): void
    {
        PendingRegistration::create([
            'name' => 'Pending Person',
            'email' => 'pending.person@gmail.com',
            'password' => 'their-own-password',
            'expires_at' => now()->addMinutes(60),
        ]);

        Socialite::fake('google', $this->googleAccount('pending.person@gmail.com', 'g-pending'));

        // Google-verified identities are NEVER sent through the email
        // verification flow — straight to the shop as a verified customer.
        $this->get(route('google.callback'))
            ->assertRedirect(route('home'));

        $this->assertAuthenticated();

        $user = User::where('email', 'pending.person@gmail.com')->firstOrFail();
        $this->assertSame(User::ROLE_CUSTOMER, $user->role);
        $this->assertSame('g-pending', $user->google_id);
        $this->assertNotNull($user->email_verified_at);

        // The pending row is left untouched (its emailed link still works
        // and resolves through the existing-account branch — no duplicate).
        $this->assertSame(1, PendingRegistration::count());
        $this->assertSame(1, User::where('email', 'pending.person@gmail.com')->count());
    }

    /* ------------------------------------------------------------------
     | Callback — configuration guard
     * ---------------------------------------------------------------- */

    public function test_callback_without_configuration_returns_to_login_with_an_error(): void
    {
        config()->set('services.google.client_id', null);

        Socialite::fake('google', $this->googleAccount('someone@gmail.com'));

        $this->get(route('google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'someone@gmail.com')->count());
    }
}

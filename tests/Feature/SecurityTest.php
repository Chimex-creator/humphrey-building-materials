<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Security & validation review: login lockout, hardening headers, upload
 * rules, privilege escalation at registration and cross-customer IDOR.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------
     | Helpers
     * ---------------------------------------------------------------- */

    private function staff(string $role = User::ROLE_ADMIN): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER, 'is_active' => true]);
    }

    private function orderFor(User $customer): Order
    {
        return Order::create([
            'order_number' => 'ORD-'.strtoupper(substr(uniqid(), -6)),
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '08031234567',
            'delivery_address' => '',
            'delivery_option' => 'pickup',
            'preferred_delivery_date' => now()->addDay()->toDateString(),
            'payment_option' => 'pay_on_delivery',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 4000,
            'delivery_fee' => 0,
            'total' => 4000,
        ]);
    }

    private function throttleKeyFor(string $email): string
    {
        return Str::lower($email).'|127.0.0.1';
    }

    /* ------------------------------------------------------------------
     | 1. Login brute-force protection
     * ---------------------------------------------------------------- */

    public function test_login_is_locked_out_after_five_failed_attempts(): void
    {
        $user = $this->customer();
        $key = $this->throttleKeyFor($user->email);
        RateLimiter::clear($key);

        for ($i = 0; $i < 5; $i++) {
            $this->from('/login')->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password-'.$i,
            ])->assertRedirect('/login')->assertSessionHasErrors('email');
        }

        // The 6th attempt is refused before the password is even checked —
        // and it is refused even though this attempt would have succeeded.
        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $errors = session('errors')->get('email');
        $this->assertStringContainsString('Too many login attempts', $errors[0]);

        RateLimiter::clear($key);
    }

    public function test_a_correct_password_logs_in_once_the_lockout_is_cleared(): void
    {
        $user = $this->customer();
        RateLimiter::clear($this->throttleKeyFor($user->email));

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_successful_login_clears_the_failure_counter(): void
    {
        $user = $this->customer();
        $key = $this->throttleKeyFor($user->email);
        RateLimiter::clear($key);

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGreaterThan(0, RateLimiter::remaining($key, 5));

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('home'));

        $this->assertSame(0, RateLimiter::attempts($key));
    }

    /* ------------------------------------------------------------------
     | 2. Baseline security headers
     * ---------------------------------------------------------------- */

    public function test_every_response_carries_the_hardening_headers(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /* ------------------------------------------------------------------
     | 3. File upload validation (no SVG upload = no stored XSS)
     * ---------------------------------------------------------------- */

    public function test_svg_product_images_are_rejected(): void
    {
        Storage::fake('public');
        $admin = $this->staff();
        $category = Category::firstOrCreate(
            ['slug' => 'cement'],
            ['name' => 'Cement']
        );

        $payload = [
            'category_id' => $category->id,
            'name' => 'Evil Vector',
            'price' => 100,
            'stock_quantity' => 5,
            'status' => 'active',
            'image' => UploadedFile::fake()->create('payload.svg', 10, 'image/svg+xml'),
        ];

        $this->actingAs($admin)
            ->post(route('admin.products.store'), $payload)
            ->assertSessionHasErrors('image');

        $this->assertDatabaseMissing('products', ['name' => 'Evil Vector']);
    }

    public function test_a_normal_jpeg_product_image_is_accepted(): void
    {
        Storage::fake('public');
        $admin = $this->staff();
        $category = Category::firstOrCreate(
            ['slug' => 'cement'],
            ['name' => 'Cement']
        );

        $payload = [
            'category_id' => $category->id,
            'name' => 'Dangote 42.5R',
            'price' => 8500,
            'stock_quantity' => 40,
            'status' => 'active',
            'image' => UploadedFile::fake()->create('cement.jpg', 64, 'image/jpeg'),
        ];

        $this->actingAs($admin)
            ->post(route('admin.products.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.products.index'));

        $this->assertDatabaseHas('products', ['name' => 'Dangote 42.5R']);
    }

    /* ------------------------------------------------------------------
     | 4. Registration cannot escalate privilege
     * ---------------------------------------------------------------- */

    public function test_registering_ignores_role_and_account_status_fields(): void
    {
        $this->post('/register', [
            'name' => 'Sneaky Person',
            'email' => 'sneaky@example.test',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
            'role' => User::ROLE_ADMIN,
            'is_active' => 0,
            'allow_partial_payment' => 1,
            'email_verified_at' => now(),
        ]);

        // Phase 2: registration only creates a PENDING registration —
        // no permanent account exists until the signed email link is used.
        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.test']);
        $pending = \App\Models\PendingRegistration::where('email', 'sneaky@example.test')->firstOrFail();

        // Complete the real verification workflow.
        $this->get($pending->verificationUrl())->assertRedirect(route('home'));

        $user = User::where('email', 'sneaky@example.test')->firstOrFail();

        $this->assertSame(User::ROLE_CUSTOMER, $user->role);
        $this->assertTrue((bool) $user->is_active);
        // Partial payment was removed from the scope — the column is gone.
        $this->assertFalse(array_key_exists('allow_partial_payment', $user->getAttributes()));
        $this->assertNotNull($user->email_verified_at);
        // The pending registration is completed/removed.
        $this->assertDatabaseMissing('pending_registrations', ['email' => 'sneaky@example.test']);
    }

    /* ------------------------------------------------------------------
     | 5. Cross-customer access (IDOR)
     * ---------------------------------------------------------------- */

    public function test_a_customer_cannot_open_another_customers_order(): void
    {
        $owner = $this->customer();
        $intruder = $this->customer();
        $order = $this->orderFor($owner);

        $this->actingAs($intruder)
            ->get(route('orders.show', $order))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->get(route('orders.payment', $order))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->get(route('orders.return.create', $order))
            ->assertForbidden();
    }

    public function test_the_owner_can_still_open_their_own_order(): void
    {
        $owner = $this->customer();
        $order = $this->orderFor($owner);

        $this->actingAs($owner)
            ->get(route('orders.show', $order))
            ->assertOk();

        $this->actingAs($owner)
            ->get(route('orders.payment', $order))
            ->assertOk();
    }

    public function test_a_guest_is_redirected_instead_of_seeing_an_order(): void
    {
        $owner = $this->customer();
        $order = $this->orderFor($owner);

        $this->get(route('orders.show', $order))->assertRedirect(route('login'));
    }
}

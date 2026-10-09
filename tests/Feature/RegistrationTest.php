<?php

namespace Tests\Feature;

use App\Models\PendingRegistration;
use App\Models\User;
use App\Notifications\VerifyPendingRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Phase 2 — true pending registration + email verification.
 *
 * The contract under test:
 *   register → PENDING row only (no User) → verification email →
 *   signed link → permanent verified account → pending row removed →
 *   authenticated, with customer-only access unlocked.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Miracle Paul',
            'email' => 'miraclepaul728@gmail.com',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ], $overrides);
    }

    private function register(array $overrides = [])
    {
        return $this->post('/register', $this->validInput($overrides));
    }

    /* ------------------------------------------------------------------
     | Happy path
     * ---------------------------------------------------------------- */

    public function test_valid_registration_creates_a_pending_registration_and_sends_the_email(): void
    {
        Notification::fake();

        $this->register()
            ->assertRedirect(route('registration.pending'))
            ->assertSessionHas('status');

        // No permanent account yet.
        $this->assertDatabaseMissing('users', ['email' => 'miraclepaul728@gmail.com']);

        $pending = PendingRegistration::where('email', 'miraclepaul728@gmail.com')->firstOrFail();
        $this->assertSame('Miracle Paul', $pending->name);
        $this->assertNotNull($pending->expires_at);

        // Real verification email triggered through the notification pipeline.
        Notification::assertSentTo($pending, VerifyPendingRegistration::class);
    }

    public function test_password_is_never_stored_in_plaintext(): void
    {
        Notification::fake();
        $this->register();

        $pending = PendingRegistration::firstOrFail();

        $this->assertNotSame('secret-pass-1', $pending->password);
        $this->assertTrue(Hash::check('secret-pass-1', $pending->password));
    }

    public function test_the_pending_page_is_dedicated_and_offers_resend(): void
    {
        Notification::fake();
        $this->register();

        $this->get(route('registration.pending'))
            ->assertOk()
            ->assertSee('Check your inbox')
            ->assertSee('miraclepaul728@gmail.com')
            ->assertSee(route('registration.resend'));
    }

    public function test_successful_verification_creates_the_verified_account_and_logs_the_user_in(): void
    {
        Notification::fake();
        $this->register();

        $pending = PendingRegistration::firstOrFail();
        $this->get($pending->verificationUrl())
            ->assertRedirect(route('home'))
            ->assertSessionHas('status');

        $user = User::where('email', 'miraclepaul728@gmail.com')->firstOrFail();
        $this->assertSame(User::ROLE_CUSTOMER, $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('secret-pass-1', $user->password));

        // Pending registration completed/removed.
        $this->assertDatabaseMissing('pending_registrations', ['email' => 'miraclepaul728@gmail.com']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_verified_customer_can_log_in_afterwards(): void
    {
        Notification::fake();
        $this->register();
        $this->get(PendingRegistration::firstOrFail()->verificationUrl());
        $this->post(route('logout'));

        $this->post('/login', [
            'email' => 'miraclepaul728@gmail.com',
            'password' => 'secret-pass-1',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));
    }

    /* ------------------------------------------------------------------
     | Access control before verification
     * ---------------------------------------------------------------- */

    public function test_a_pending_registration_grants_no_customer_access(): void
    {
        Notification::fake();
        $this->register();
        $this->assertGuest();

        // Every customer-only screen requires an authenticated session —
        // there is no User row to authenticate at all.
        foreach (['/profile', '/orders', '/notifications', '/checkout', '/orders'] as $uri) {
            $this->get($uri)->assertRedirect(route('login'));
        }
    }

    public function test_login_is_impossible_before_verification(): void
    {
        Notification::fake();
        $this->register();

        $this->post('/login', [
            'email' => 'miraclepaul728@gmail.com',
            'password' => 'secret-pass-1',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /* ------------------------------------------------------------------
     | Duplicates
     * ---------------------------------------------------------------- */

    public function test_registering_with_an_existing_account_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->register(['email' => 'taken@example.test'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('pending_registrations', ['email' => 'taken@example.test']);
    }

    public function test_a_duplicate_pending_registration_is_refreshed_and_resends_one_link(): void
    {
        Notification::fake();

        $this->register();
        $first = PendingRegistration::firstOrFail();

        // Same email again with a newer password.
        $this->register(['password' => 'second-pass-1', 'password_confirmation' => 'second-pass-1'])
            ->assertRedirect(route('registration.pending'))
            ->assertSessionHas('status');

        $this->assertSame(1, PendingRegistration::count());
        $fresh = PendingRegistration::firstOrFail();
        $this->assertTrue($fresh->is($first));
        $this->assertTrue(Hash::check('second-pass-1', $fresh->password));

        Notification::assertSentTo($fresh, VerifyPendingRegistration::class);
    }

    /* ------------------------------------------------------------------
     | Resend
     * ---------------------------------------------------------------- */

    public function test_resend_sends_a_fresh_link_and_refreshes_the_expiry(): void
    {
        Notification::fake();
        $this->register();

        $pending = PendingRegistration::firstOrFail();
        $pending->forceFill(['expires_at' => now()->subHour()])->save();

        $this->post(route('registration.resend'), ['email' => 'miraclepaul728@gmail.com'])
            ->assertRedirect(route('registration.pending'))
            ->assertSessionHas('status');

        $this->assertTrue($pending->fresh()->expires_at->isFuture());
        Notification::assertSentTo($pending, VerifyPendingRegistration::class);
    }

    public function test_resend_for_an_unknown_email_does_not_disclose_account_state(): void
    {
        Notification::fake();

        $this->post(route('registration.resend'), ['email' => 'nobody@example.test'])
            ->assertRedirect(route('registration.pending'))
            ->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_resend_for_an_existing_account_points_to_login(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'exists@example.test']);

        $this->post(route('registration.resend'), ['email' => 'exists@example.test'])
            ->assertRedirect(route('login'));

        Notification::assertNothingSent();
    }

    /* ------------------------------------------------------------------
     | Bad links
     * ---------------------------------------------------------------- */

    public function test_an_invalid_verification_link_is_handled_with_a_friendly_page(): void
    {
        Notification::fake();
        $this->register();
        $pending = PendingRegistration::firstOrFail();

        // Valid signature, wrong hash → the controller must reject it.
        $url = URL::signedRoute('registration.verify', [
            'id' => $pending->id,
            'hash' => sha1('someone-else@example.test'),
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('not valid', false);

        $this->assertDatabaseMissing('users', ['email' => 'miraclepaul728@gmail.com']);
        $this->assertDatabaseHas('pending_registrations', ['email' => 'miraclepaul728@gmail.com']);
    }

    public function test_an_unknown_pending_id_is_handled_with_a_friendly_page(): void
    {
        Notification::fake();
        $this->register();
        $pending = PendingRegistration::firstOrFail();

        $url = URL::signedRoute('registration.verify', [
            'id' => $pending->id + 999,
            'hash' => sha1($pending->email),
        ]);

        $this->get($url)->assertOk()->assertSee('not valid', false);
        $this->assertDatabaseMissing('users', ['email' => 'miraclepaul728@gmail.com']);
    }

    public function test_a_tampered_verification_link_is_rejected(): void
    {
        Notification::fake();
        $this->register();
        $pending = PendingRegistration::firstOrFail();

        // Re-writing the id (or hash) breaks the signature.
        $url = $pending->verificationUrl();
        $tampered = preg_replace('#/verify/\d+#', '/verify/'.($pending->id + 1), $url);

        // Friendly "not valid" page instead of a raw 403 — the account
        // is still never created.
        $this->get($tampered)->assertOk()->assertSee('not valid', false);

        $this->assertDatabaseMissing('users', ['email' => 'miraclepaul728@gmail.com']);
    }

    public function test_a_link_without_any_signature_is_rejected_with_a_friendly_page(): void
    {
        Notification::fake();
        $this->register();
        $pending = PendingRegistration::firstOrFail();

        // Bare path, no signature at all — must not create anything.
        $this->get('/register/verify/'.$pending->id.'/'.sha1($pending->email))
            ->assertOk()
            ->assertSee('not valid', false);

        $this->assertDatabaseMissing('users', ['email' => 'miraclepaul728@gmail.com']);
        $this->assertDatabaseHas('pending_registrations', ['email' => 'miraclepaul728@gmail.com']);
    }

    public function test_verification_link_survives_a_protocol_and_host_change(): void
    {
        // Production regression: links generated behind http (or one
        // hostname) used to 403 when opened through https / another host
        // (the old `signed` middleware validated the absolute URL only).
        Notification::fake();
        $this->register();

        $pending = PendingRegistration::firstOrFail();
        $url = $pending->verificationUrl();

        $parts = parse_url($url);
        $this->assertSame('/register/verify/'.$pending->id.'/'.sha1($pending->email), $parts['path']);

        // Same link, different scheme + host (as after an http→https upgrade).
        $openedElsewhere = 'https://humphrey-building-materials-production.up.railway.app'
            .$parts['path'].'?'.$parts['query'];

        $this->get($openedElsewhere)
            ->assertRedirect(route('home'))
            ->assertSessionHas('status');

        $user = User::where('email', 'miraclepaul728@gmail.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseMissing('pending_registrations', ['email' => 'miraclepaul728@gmail.com']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_expired_link_is_rejected_until_a_fresh_one_is_requested(): void
    {
        Notification::fake();
        $this->register();

        $pending = PendingRegistration::firstOrFail();
        $pending->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->get($pending->verificationUrl())
            ->assertOk()
            ->assertSee('has expired', false);

        $this->assertDatabaseMissing('users', ['email' => 'miraclepaul728@gmail.com']);

        // Resend refreshes the expiry, and the same style of link now works.
        $this->post(route('registration.resend'), ['email' => 'miraclepaul728@gmail.com']);
        $this->get($pending->fresh()->verificationUrl())->assertRedirect(route('home'));
        $this->assertDatabaseHas('users', ['email' => 'miraclepaul728@gmail.com']);
    }

    public function test_an_already_used_link_cannot_create_a_second_account(): void
    {
        Notification::fake();
        $this->register();
        $url = PendingRegistration::firstOrFail()->verificationUrl();

        $this->get($url)->assertRedirect(route('home'));
        $this->assertSame(1, User::where('email', 'miraclepaul728@gmail.com')->count());

        // The pending row is gone, so the signed link now reports the
        // registration as already completed.
        $this->get($url)->assertOk()->assertSee('not valid', false);
        $this->assertSame(1, User::where('email', 'miraclepaul728@gmail.com')->count());
    }

    public function test_verification_does_not_duplicate_an_account_that_appeared_in_the_meantime(): void
    {
        Notification::fake();
        $this->register();
        $pending = PendingRegistration::firstOrFail();

        // An account with the same email shows up before the link is clicked
        // (e.g. created by staff or an earlier legacy registration).
        User::factory()->create([
            'email' => 'miraclepaul728@gmail.com',
            'email_verified_at' => null,
        ]);

        $this->get($pending->verificationUrl())->assertRedirect(route('login'));

        $this->assertSame(1, User::where('email', 'miraclepaul728@gmail.com')->count());
        $this->assertNotNull(User::where('email', 'miraclepaul728@gmail.com')->first()->email_verified_at);
        $this->assertDatabaseMissing('pending_registrations', ['email' => 'miraclepaul728@gmail.com']);
    }

    /* ------------------------------------------------------------------
     | Validation
     * ---------------------------------------------------------------- */

    public function test_registration_validates_its_input(): void
    {
        $this->post('/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors(['name', 'email', 'password']);

        $this->assertDatabaseCount('pending_registrations', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_guests_are_sent_to_login_before_reaching_verification_screens(): void
    {
        // The legacy (already-account) verification screens stay behind auth.
        $this->get(route('verification.notice'))->assertRedirect(route('login'));
        $this->post(route('verification.resend'))->assertRedirect(route('login'));
    }
}

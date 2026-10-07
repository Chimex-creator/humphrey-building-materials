<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * True pending-registration flow (Phase 2 of the final master prompt).
 *
 *   guest → register → validate → PENDING REGISTRATION (no User row yet)
 *         → real verification email → signed link click
 *         → permanent account created + verified + pending row removed
 *
 * Until the link is clicked there is no account, so `auth` keeps the
 * person out of every customer-only screen.
 */
class RegisterController extends Controller
{
    /** Show the registration form. */
    public function show()
    {
        return view('auth.register');
    }

    /**
     * Handle registration: store the details as a pending registration
     * (password hashed by the model cast) and email the signed link.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Duplicate pending registration → refresh it (latest name/password)
        // and send a fresh link instead of failing.
        $pending = PendingRegistration::updateOrCreate(
            ['email' => $data['email']],
            [
                'name' => $data['name'],
                'password' => $data['password'], // re-hashed by the hashed cast
                'expires_at' => PendingRegistration::expiry(),
                'ip_address' => $request->ip(),
            ]
        );

        if (! $this->sendLink($pending)) {
            return redirect()
                ->route('registration.pending')
                ->with('error', 'We could not send the verification email right now. '
                    .'Your registration was saved — please request the link again in a moment.')
                ->with('pending_email', $data['email']);
        }

        return redirect()
            ->route('registration.pending')
            ->with('status', 'Almost there! We emailed a verification link to '.$data['email']
                .'. Click it to activate your account.')
            ->with('pending_email', $data['email']);
    }

    /** Dedicated "check your inbox" page shown right after registering. */
    public function pending(Request $request)
    {
        return view('auth.pending-verification', [
            'email' => $request->query('email') ?: session('pending_email'),
        ]);
    }

    /** Resend the verification link (also handles repeat submissions). */
    public function resend(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $pending = PendingRegistration::where('email', $data['email'])->first();

        if ($pending) {
            // A fresh link gets a fresh expiry; the old link stops working.
            $pending->update(['expires_at' => PendingRegistration::expiry()]);

            if (! $this->sendLink($pending)) {
                return redirect()
                    ->route('registration.pending')
                    ->with('error', 'We could not send the verification email right now — please try again shortly.')
                    ->with('pending_email', $data['email']);
            }

            return redirect()
                ->route('registration.pending')
                ->with('status', 'A new verification link was sent to '.$data['email'].'.')
                ->with('pending_email', $data['email']);
        }

        if (User::where('email', $data['email'])->exists()) {
            return redirect()
                ->route('login')
                ->with('status', 'An account for '.$data['email']
                    .' already exists — log in, or use "forgot password" if you need a new one.');
        }

        // Nothing to send for — same message either way so we never
        // disclose whether an email address is registered.
        return redirect()
            ->route('registration.pending')
            ->with('status', 'If a pending registration exists for '.$data['email']
                .', a new verification link has been sent.')
            ->with('pending_email', $data['email']);
    }

    /**
     * The signed link from the email: validate it, then create the
     * permanent verified customer account exactly once.
     */
    public function verify(Request $request, string $id, string $hash)
    {
        $pending = PendingRegistration::find($id);

        // Unknown id, or hash that does not match this email → bogus/tampered.
        if (! $pending || ! hash_equals(sha1($pending->email), (string) $hash)) {
            return view('auth.verification-result', [
                'title' => 'This verification link is not valid',
                'message' => 'The link may have already been used, or it was changed in transit. '
                    .'If you still need to verify, request a fresh link below.',
                'email' => session('pending_email'),
            ]);
        }

        if ($pending->isExpired()) {
            return view('auth.verification-result', [
                'title' => 'This verification link has expired',
                'message' => 'For your security, verification links last '
                    .PendingRegistration::EXPIRE_MINUTES.' minutes. Request a fresh link below and we will email it again.',
                'email' => $pending->email,
            ]);
        }

        $result = DB::transaction(function () use ($pending) {
            // Re-read inside the transaction: a double click must not
            // create two accounts.
            $fresh = PendingRegistration::whereKey($pending->id)->first();

            if (! $fresh) {
                return null; // already completed by a concurrent request
            }

            $existing = User::where('email', $fresh->email)->first();

            if ($existing) {
                // An account appeared after this registration started —
                // never create a duplicate.
                $fresh->delete();

                if (! $existing->hasVerifiedEmail()) {
                    $existing->forceFill(['email_verified_at' => now()])->save();
                }

                return 'existing';
            }

            try {
                $user = User::create([
                    'name' => $fresh->name,
                    'email' => $fresh->email,
                    'password' => $fresh->password, // already bcrypt — the hashed cast passes it through
                    'role' => User::ROLE_CUSTOMER,
                    'email_verified_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Race lost against another request — treat as existing.
                return 'existing';
            }

            $fresh->delete();

            return $user;
        });

        if ($result instanceof User) {
            Auth::login($result);

            return redirect()
                ->route('home')
                ->with('status', 'Your email has been verified — welcome, '.$result->name.'!');
        }

        if ($result === 'existing') {
            return redirect()
                ->route('login')
                ->with('status', 'That email already has an account and is now verified — please log in.');
        }

        return view('auth.verification-result', [
            'title' => 'This verification link has already been used',
            'message' => 'This registration was completed already. If that was you, simply log in — '
                .'otherwise request a new link below.',
            'email' => session('pending_email'),
        ]);
    }

    /** Send the email, swallowing transport errors so registration still succeeds. */
    private function sendLink(PendingRegistration $pending): bool
    {
        try {
            $pending->sendVerificationEmail();

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}

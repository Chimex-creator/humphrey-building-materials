<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * "Continue with Google" — CUSTOMER sign-in only (Laravel Socialite).
 *
 * Rules enforced here:
 *  - Google never changes anyone's role; staff accounts are refused and
 *    must keep using email + password on the normal login.
 *  - An existing customer with the same email is linked, never duplicated.
 *  - A pending (unverified) email/password registration for the same
 *    address is left untouched — that flow must finish first.
 *  - A Google-authenticated email counts as verified.
 */
class GoogleLoginController extends Controller
{
    /** Send the customer to Google's consent screen. */
    public function redirect()
    {
        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            return redirect()
                ->route('login')
                ->with('error', 'Google sign-in is not configured yet. Please log in with your email and password.');
        }

        return Socialite::driver('google')->redirect();
    }

    /** Handle Google's callback and sign the customer in. */
    public function callback(Request $request)
    {
        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            return redirect()
                ->route('login')
                ->with('error', 'Google sign-in is not configured yet. Please log in with your email and password.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('login')
                ->with('error', 'Google sign-in failed or was cancelled. Please try again.');
        }

        $email = strtolower(trim((string) $googleUser->getEmail()));

        if ($email === '') {
            return redirect()
                ->route('login')
                ->with('error', 'Google did not share an email address for that account — please log in with your password.');
        }

        // A normal email/password registration for this address is still
        // waiting for its verification link. Never bypass or destroy it:
        // send the person back to that flow to finish it first.
        if (PendingRegistration::where('email', $email)->exists()) {
            return redirect()
                ->route('registration.pending')
                ->with('pending_email', $email)
                ->with('status', 'A registration for '.$email.' is still waiting for email verification. '
                    .'Please click the link we emailed you (or press "Resend Verification Email") to finish '
                    .'that registration first, then you can log in normally.');
        }

        $googleId = (string) $googleUser->getId();

        $user = User::where('email', $email)
            ->orWhere('google_id', $googleId)
            ->first();

        if ($user) {
            // Google sign-in is customer-only: an admin/staff account must
            // never log in (or be changed) through Google.
            if (! $user->isCustomer()) {
                return redirect()
                    ->route('login')
                    ->with('error', 'Google sign-in is for customer accounts only. '
                        .($user->roleLabel()).' accounts must log in with email and password.');
            }

            if (! $user->is_active) {
                return redirect()
                    ->route('login')
                    ->with('error', 'This account has been deactivated. Please contact the administrator.');
            }

            // Google verified the email address, and links the identity.
            $changes = [];
            if (! $user->hasVerifiedEmail()) {
                $changes['email_verified_at'] = now();
            }
            if ($user->google_id !== $googleId) {
                $changes['google_id'] = $googleId;
            }
            if ($changes !== []) {
                $user->forceFill($changes)->save();
            }
        } else {
            try {
                $user = User::create([
                    'name' => $googleUser->getName() ?: ($googleUser->getNickname() ?: 'Google Customer'),
                    'email' => $email,
                    'password' => Str::random(40), // random; the customer resets it later if ever needed
                    'role' => User::ROLE_CUSTOMER,
                    'email_verified_at' => now(), // Google authenticated the address
                    'is_active' => true,
                    'google_id' => $googleId,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Lost a race with another request — reuse that account.
                $user = User::where('email', $email)->first();
                if (! $user || ! $user->isCustomer()) {
                    return redirect()
                        ->route('login')
                        ->with('error', 'An account for that email already exists — please log in with your password.');
                }
            }
        }

        // Same session hygiene as the normal login (LoginController::store).
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()
            ->intended(route('home'))
            ->with('status', 'Welcome, '.$user->name.'!');
    }
}

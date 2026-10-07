<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    /** How many failed attempts one email+IP combination gets per minute. */
    private const MAX_ATTEMPTS = 5;

    /** Show the login form. */
    public function show()
    {
        return view('auth.login');
    }

    /** Handle login. */
    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Brute-force protection: a wrong password counts as one failed
        // attempt for that email+IP pair; 5 in a minute locks it for 60s.
        $throttleKey = Str::lower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return back()->withErrors([
                'email' => 'Too many login attempts. Please try again in '
                    .RateLimiter::availableIn($throttleKey).' seconds.',
            ])->onlyInput('email');
        }

        // attempt() checks email + password against the database.
        // remember = checkbox "Keep me logged in".
        $remember = $request->boolean('remember');
        if (! Auth::attempt($credentials, $remember)) {
            RateLimiter::hit($throttleKey, 60);

            // Wrong email or password — same message for security.
            return back()->withErrors([
                'email' => 'The provided credentials do not match our records.',
            ])->onlyInput('email');
        }

        // Right password — forget the failure count.
        RateLimiter::clear($throttleKey);

        // Regenerate session ID (prevents session fixation attacks).
        $request->session()->regenerate();

        // Deactivated accounts cannot log in.
        if (! Auth::user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors([
                'email' => 'This account has been deactivated. Please contact the administrator.',
            ])->onlyInput('email');
        }

        // New users must verify their email before using the account.
        if (! Auth::user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice')->with('status',
                'Please verify your email address before continuing.');
        }

        // Staff go to the admin dashboard; customers go to the shop/home.
        if (Auth::user()->isStaff()) {
            return redirect()->intended(route('admin.dashboard'))->with('status', 'Welcome back, '.Auth::user()->name.'!');
        }

        return redirect()->intended(route('home'))->with('status', 'Welcome back, '.Auth::user()->name.'!');
    }

    /** Handle logout. */
    public function logout(Request $request)
    {
        Auth::logout();

        // Invalidate the session + regenerate the CSRF token.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'You have been logged out.');
    }
}

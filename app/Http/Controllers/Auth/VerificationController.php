<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    /** "Please check your email" page. */
    public function show()
    {
        return view('auth.verify-email');
    }

    /** Handle the signed link clicked from the email. */
    public function verify(Request $request)
    {
        // URL format: /email/verify/{id}/{hash} — load the user by ID.
        $user = User::find($request->route('id'));

        if (! $user) {
            abort(404, 'User not found for verification.');
        }

        // The hash in the URL must match sha1 of the user's email.
        if (! hash_equals((string) $request->route('hash'), sha1($user->getEmailForVerification()))) {
            abort(403, 'Invalid verification link.');
        }

        if ($user->hasVerifiedEmail()) {
            // Already verified — just go home.
            return redirect()->intended(route('home'))->with('status', 'Email already verified.');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->intended(route('home'))->with('status', 'Your email has been verified. Thank you!');
    }

    /** Resend the verification email (rate-limited in routes). */
    public function resend(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('home'));
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'A new verification link has been sent to your email address.');
    }
}

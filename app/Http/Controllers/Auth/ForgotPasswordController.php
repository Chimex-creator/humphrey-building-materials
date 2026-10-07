<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    /** Show "forgot password" form. */
    public function show()
    {
        return view('auth.forgot-password');
    }

    /** Send the reset link to the user's email. */
    public function send(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        // Password::sendResetLink looks up the user and emails a signed reset URL.
        // Status can be: PASSWORD_SENT (success) or PASSWORD_RESET_LINK_NOT_FOUND.
        $status = Password::sendResetLink($request->only('email'));

        // Always show the same message (don't reveal whether the email exists).
        return $status === Password::RESET_LINK_SENT
            ? back()->with('status', 'If that email address exists, a password reset link has been sent.')
            : back()->withErrors(['email' => __('passwords.user')]);
    }
}

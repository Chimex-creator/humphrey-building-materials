<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Notifications\PasswordChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ChangePasswordController extends Controller
{
    /** Show change-password form. */
    public function show()
    {
        return view('profile.change-password');
    }

    /** Save the new password (requires current password). */
    public function update(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', Password::min(8)],
        ]);

        // Verify the current password first.
        if (! Hash::check($request->current_password, $request->user()->password)) {
            return back()->withErrors(['current_password' => 'Your current password is incorrect.']);
        }

        $request->user()->update([
            'password' => $request->password, // hashed automatically
        ]);

        // Security event → in-app + email (critical list).
        $request->user()->notify(new PasswordChanged);

        return redirect()->route('profile.edit')->with('status', 'Your password has been changed successfully.');
    }
}

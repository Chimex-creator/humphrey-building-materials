<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /** Show the profile page. */
    public function edit(Request $request)
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    /** Update profile details (incl. optional profile picture — §31). */
    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'avatar.image' => 'Please upload an image file (JPG, PNG or WebP).',
            'avatar.max' => 'The profile picture must be smaller than 2 MB.',
        ]);

        $emailChanged = $data['email'] !== $user->email;

        $avatar = $data['avatar'] ?? null;
        unset($data['avatar']);

        $user->fill($data);

        // Swap the picture, removing the old file from storage.
        if ($avatar) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $user->avatar_path = $avatar->store('avatars', 'public');
        }

        // If the email changed, it must be verified again.
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();

            return redirect()->route('profile.edit')->with('status',
                'Profile updated. Because your email changed, please verify the new address.');
        }

        return redirect()->route('profile.edit')->with('status', 'Profile updated successfully.');
    }

    /** Remove the profile picture (optional feature — §31). */
    public function removeAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->avatar_path = null;
            $user->save();
        }

        return redirect()->route('profile.edit')->with('status', 'Profile picture removed.');
    }
}

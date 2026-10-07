<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /** List all users (staff + customers) with search. */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $roleFilter = $request->input('role');

        $query = User::latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($roleFilter && in_array($roleFilter, array_keys(User::ROLES), true)) {
            $query->where('role', $roleFilter);
        }

        $users = $query->paginate(10)->withQueryString();

        return view('admin.users.index', compact('users', 'search', 'roleFilter'));
    }

    /** Show "create staff account" form. */
    public function create()
    {
        // Only admins can create staff — customers self-register.
        $roles = User::ROLES;
        unset($roles[User::ROLE_CUSTOMER]); // staff creation only

        return view('admin.users.create', compact('roles'));
    }

    /** Save a new staff account. */
    public function store(Request $request, ActivityLogger $logger)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_INVENTORY, User::ROLE_SALES])],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
            'phone' => $data['phone'] ?? null,
            'is_active' => true,
            'email_verified_at' => now(), // staff created by admin — no self-verification needed
        ]);

        // PHASE 12 — audit trail (Master Prompt §47).
        $logger->log('user.created', $user, sprintf(
            'New %s account created for %s (%s).',
            User::ROLES[$data['role']] ?? $data['role'],
            $data['name'],
            $data['email']
        ), [
            'role' => $data['role'],
            'email' => $data['email'],
        ]);

        return redirect()->route('admin.users.index')->with('status', 'Staff account created successfully.');
    }

    /** Show edit form for a user. */
    public function edit(User $user)
    {
        $roles = User::ROLES;

        return view('admin.users.edit', compact('user', 'roles'));
    }

    /** Update a user (name, email, role, active status, optional password). */
    public function update(Request $request, User $user, ActivityLogger $logger)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        // Final Spec §29 — admins must NEVER set or view a customer's
        // password. Staff passwords are still admin-manageable; customers
        // recover access only through the secure reset-link process.
        $passwordChanged = ! empty($data['password']);
        if ($passwordChanged && $user->role === User::ROLE_CUSTOMER) {
            return back()->withErrors([
                'password' => 'Admins cannot set a customer\'s password — send a password reset link instead.',
            ]);
        }

        // PHASE 12 — remember the sensitive values so the change can be logged.
        $previousRole = $user->role;
        $previousActive = (bool) $user->is_active;

        // Safety: admin cannot demote or deactivate themselves.
        if ($user->id === auth()->id()) {
            if ($data['role'] !== User::ROLE_ADMIN) {
                return back()->withErrors(['role' => 'You cannot change your own admin role.']);
            }
            if (! ($request->boolean('is_active'))) {
                return back()->withErrors(['is_active' => 'You cannot deactivate your own account.']);
            }
        }

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role = $data['role'];
        $user->phone = $data['phone'] ?? null;
        $user->is_active = $request->boolean('is_active');

        // Only update password if one was typed.
        if ($passwordChanged) {
            $user->password = $data['password'];
        }

        $user->save();

        // Security event → in-app + email (critical list).
        if ($passwordChanged) {
            $user->notify(new PasswordChanged('admin'));
        }

        // PHASE 12 — role changes and deactivations are sensitive (Master §47).
        if ($user->role !== $previousRole) {
            $logger->log('user.role_changed', $user, sprintf(
                'Role of %s changed from "%s" to "%s".',
                $user->name,
                User::ROLES[$previousRole] ?? $previousRole,
                User::ROLES[$user->role] ?? $user->role
            ), [
                'from_role' => $previousRole,
                'to_role' => $user->role,
            ]);
        }

        $nowActive = (bool) $user->is_active;
        if ($nowActive !== $previousActive) {
            $logger->log($nowActive ? 'user.activated' : 'user.deactivated', $user, sprintf(
                '%s account %s.',
                $user->name,
                $nowActive ? 'reactivated' : 'deactivated'
            ), [
                'from_active' => $previousActive,
                'to_active' => $nowActive,
            ]);
        }

        return redirect()->route('admin.users.index')->with('status', 'User updated successfully.');
    }

    /**
     * Final Spec §39 — bulk activate / deactivate accounts (admins only).
     *
     * Mirrors the customer bulk action: only rows that really change are
     * counted, the run is written to the audit trail once, and an admin can
     * never deactivate their own account by accident.
     */
    public function bulk(Request $request, ActivityLogger $logger)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate'])],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $active = $data['action'] === 'activate';

        $ids = User::whereIn('id', $data['user_ids']);
        if (! $active) {
            $ids->where('id', '!=', (int) auth()->id()); // never lock yourself out
        }
        $ids = $ids->pluck('id');

        $changed = 0;
        foreach ($ids as $id) {
            $user = User::whereKey($id)->first();
            if ($user && (bool) $user->is_active !== $active) {
                $user->is_active = $active;
                $user->save();
                $changed++;
            }
        }

        if ($changed > 0) {
            $logger->log('users.bulk_'.$data['action'], null, sprintf(
                '%d account(s) %s in one bulk action.',
                $changed,
                $active ? 'activated' : 'deactivated'
            ), [
                'count' => $changed,
                'ids' => $ids->all(),
            ]);
        }

        return back()->with('status', $changed > 0
            ? $changed.' account(s) '.($active ? 'activated' : 'deactivated').'.'
            : 'Nothing to change — the selected accounts were already in that state.');
    }

    /**
     * Final Spec §29 — the ONLY way an admin helps a customer back into
     * their account: send Laravel's one-time, expiring, secure reset link.
     * The existing password is never revealed and never set directly.
     */
    public function sendResetLink(User $user, ActivityLogger $logger)
    {
        if ($user->role !== User::ROLE_CUSTOMER) {
            return back()->with('error', 'Password reset links are for customer accounts — edit staff passwords on their edit screen.');
        }

        if (! $user->is_active) {
            return back()->with('error', 'This account is deactivated — reactivate it before sending a reset link.');
        }

        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            return back()->with('error', 'Could not send the reset link. '.$status);
        }

        // PHASE 12 — audit trail (Master Prompt §47).
        $logger->log('user.reset_link_sent', $user, sprintf(
            'Password reset link sent to %s (%s).',
            $user->email,
            $user->name
        ));

        return back()->with('status', 'Password reset link sent to '.$user->email.'.');
    }
}

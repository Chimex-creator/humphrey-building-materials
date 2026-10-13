<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
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

    /** Update a user (name, email, role, active status). */
    public function update(Request $request, User $user, ActivityLogger $logger)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // PHASE 9 — customer identity (name / email / phone) is never
        // admin-editable: personal-detail corrections go through the
        // customer, and access recovery goes through the reset-link flow.
        // Role and account status remain admin-managed.
        if ($user->role === User::ROLE_CUSTOMER) {
            $identityChanged = $data['name'] !== $user->name
                || $data['email'] !== $user->email
                || (string) ($data['phone'] ?? '') !== (string) ($user->phone ?? '');

            if ($identityChanged) {
                return back()->withErrors([
                    'name' => 'Customer personal details (name, email, phone) cannot be changed here — '
                        .'manage only the role and account status. Use the password reset link for access recovery.',
                ]);
            }
        }

        // PHASE 12 — remember the sensitive values so the change can be logged.
        $previousRole = $user->role;
        $previousActive = (bool) $user->is_active;
        $previousEmail = $user->email;

        // Safety: admin cannot demote or deactivate themselves.
        if ($user->id === auth()->id()) {
            if ($data['role'] !== User::ROLE_ADMIN) {
                return back()->withErrors(['role' => 'You cannot change your own admin role.']);
            }
            if (! ($request->boolean('is_active'))) {
                return back()->withErrors(['is_active' => 'You cannot deactivate your own account.']);
            }
        }

        // PHASE 1 — the system must never be left without an accessible
        // administrator. No account is silently promoted; the change is
        // simply refused until another active admin exists.
        $otherActiveAdmins = fn (): int => User::where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->count();

        if ($user->role === User::ROLE_ADMIN
            && $data['role'] !== User::ROLE_ADMIN
            && $otherActiveAdmins() === 0) {
            return back()->withErrors([
                'role' => 'This is the last active administrator — make another account an admin first.',
            ]);
        }

        if ($user->role === User::ROLE_ADMIN
            && $user->is_active
            && ! $request->boolean('is_active')
            && $otherActiveAdmins() === 0) {
            return back()->withErrors([
                'is_active' => 'This is the last active administrator — reactivate or promote another admin first.',
            ]);
        }

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role = $data['role'];
        $user->phone = $data['phone'] ?? null;
        $user->is_active = $request->boolean('is_active');

        $user->save();

        // PHASE 9 — a staff email change is an identity change: audit it.
        if ($previousEmail !== $user->email) {
            $logger->log('user.email_changed', $user, sprintf(
                'Email of %s changed from %s to %s.',
                $user->name,
                $previousEmail,
                $user->email
            ), [
                'from_email' => $previousEmail,
                'to_email' => $user->email,
            ]);
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
     * Final Spec §29 / PHASE 9 — the ONLY way an admin recovers access for
     * ANY account (customer or staff): Laravel's one-time, expiring, secure
     * reset link. Existing passwords are never revealed or set directly.
     */
    public function sendResetLink(User $user, ActivityLogger $logger)
    {
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

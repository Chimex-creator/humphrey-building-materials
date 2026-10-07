@extends('layouts.admin')

@section('title', 'Edit User')

@section('content')
    <div class="admin-page-head">
        <h1>Edit User</h1>
        <p>Update account details, role and activation status for <strong>{{ $user->name }}</strong>.</p>
    </div>

    <div class="admin-card" style="max-width: 640px;">
        <form action="{{ route('admin.users.update', $user) }}" method="POST" novalidate>
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="name">Full Name <span class="req">*</span></label>
                <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required
                       class="{{ $errors->has('name') ? 'is-invalid' : '' }}">
                @error('name')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="email">Email <span class="req">*</span></label>
                <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required
                       class="{{ $errors->has('email') ? 'is-invalid' : '' }}">
                @error('email')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="phone">Phone</label>
                <input type="text" id="phone" name="phone" value="{{ old('phone', $user->phone) }}"
                       class="{{ $errors->has('phone') ? 'is-invalid' : '' }}">
                @error('phone')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="role">Role <span class="req">*</span></label>
                <select id="role" name="role" required class="{{ $errors->has('role') ? 'is-invalid' : '' }}">
                    @foreach ($roles as $key => $label)
                        <option value="{{ $key }}" {{ old('role', $user->role) === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                @error('role')<span class="field-error">{{ $message }}</span>@enderror
                @if ($user->id === auth()->id())
                    <small class="field-help">You cannot change your own admin role.</small>
                @endif
            </div>

            <div class="form-group">
                <label class="form-check">
                    <input type="checkbox" name="is_active" value="1"
                           {{ old('is_active', $user->is_active) ? 'checked' : '' }}
                           {{ $user->id === auth()->id() ? 'disabled' : '' }}>
                    <span>Account active (can log in)</span>
                </label>
                @error('is_active')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            @if ($user->role !== \App\Models\User::ROLE_CUSTOMER)
                <hr class="form-divider">

                <div class="form-group">
                    <label for="password">New Password <span class="optional">(optional)</span></label>
                    <input type="password" id="password" name="password"
                           class="{{ $errors->has('password') ? 'is-invalid' : '' }}">
                    @error('password')<span class="field-error">{{ $message }}</span>@enderror
                    <small class="field-help">Leave blank to keep the current password.</small>
                </div>

                <div class="form-group">
                    <label for="password_confirmation">Confirm New Password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation">
                </div>
            @endif

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="{{ route('admin.users.index') }}" class="btn btn-clear">Cancel</a>
            </div>
        </form>

        @if ($user->role === \App\Models\User::ROLE_CUSTOMER)
            <hr class="form-divider">
            <div class="form-group">
                <label>Password recovery</label>
                <p class="empty-inline">
                    Admins can never view or directly set a customer's password.
                    Send the customer Laravel's one-time, expiring reset link instead.
                </p>
                <form action="{{ route('admin.users.send-reset', $user) }}" method="POST" style="margin-top:8px;">
                    @csrf
                    <button type="submit" class="btn btn-navy"
                            onclick="return confirm('Send a password reset link to {{ $user->email }}?');">
                        📧 Send Password Reset Link
                    </button>
                </form>
            </div>
        @endif
    </div>
@endsection

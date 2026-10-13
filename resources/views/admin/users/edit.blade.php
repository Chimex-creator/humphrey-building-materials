@extends('layouts.admin')

@section('title', 'Edit User')

@section('content')
    <div class="admin-page-head">
        <h1>Edit User</h1>
        <p>Update role and activation status for <strong>{{ $user->name }}</strong>.</p>
    </div>

    <div class="admin-card" style="max-width: 640px;">
        @if ($user->role === \App\Models\User::ROLE_CUSTOMER)
            <p class="empty-inline" style="margin-bottom: 14px;">
                Customer personal details (name, email, phone) are not editable here —
                the customer corrects their own details, and access recovery uses the
                secure reset link below. Role and account status remain admin-managed.
            </p>
        @endif

        <form action="{{ route('admin.users.update', $user) }}" method="POST" novalidate>
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="name">Full Name <span class="req">*</span></label>
                <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required
                       @if ($user->role === \App\Models\User::ROLE_CUSTOMER) readonly @endif
                       class="{{ $errors->has('name') ? 'is-invalid' : '' }}">
                @error('name')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="email">Email <span class="req">*</span></label>
                <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required
                       @if ($user->role === \App\Models\User::ROLE_CUSTOMER) readonly @endif
                       class="{{ $errors->has('email') ? 'is-invalid' : '' }}">
                @error('email')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="phone">Phone</label>
                <input type="text" id="phone" name="phone" value="{{ old('phone', $user->phone) }}"
                       @if ($user->role === \App\Models\User::ROLE_CUSTOMER) readonly @endif
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

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="{{ route('admin.users.index') }}" class="btn btn-clear">Cancel</a>
            </div>
        </form>

        <hr class="form-divider">
        <div class="form-group">
            <label>Password recovery</label>
            <p class="empty-inline">
                Admins can never view or directly set an account's password.
                Send Laravel's one-time, expiring reset link instead.
            </p>
            <form action="{{ route('admin.users.send-reset', $user) }}" method="POST" style="margin-top:8px;">
                @csrf
                <button type="submit" class="btn btn-navy"
                        onclick="return confirm('Send a password reset link to {{ $user->email }}?');">
                    📧 Send Password Reset Link
                </button>
            </form>
        </div>
    </div>
@endsection

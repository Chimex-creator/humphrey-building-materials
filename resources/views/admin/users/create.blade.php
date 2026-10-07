@extends('layouts.admin')

@section('title', 'Add Staff Account')

@section('content')
    <div class="admin-page-head">
        <h1>Add Staff Account</h1>
        <p>Create a new staff login. The account is active and email-verified immediately.</p>
    </div>

    <div class="admin-card" style="max-width: 640px;">
        <form action="{{ route('admin.users.store') }}" method="POST" novalidate>
            @csrf

            <div class="form-group">
                <label for="name">Full Name <span class="req">*</span></label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required
                       class="{{ $errors->has('name') ? 'is-invalid' : '' }}">
                @error('name')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="email">Email <span class="req">*</span></label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required
                       class="{{ $errors->has('email') ? 'is-invalid' : '' }}">
                @error('email')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="phone">Phone</label>
                <input type="text" id="phone" name="phone" value="{{ old('phone') }}"
                       class="{{ $errors->has('phone') ? 'is-invalid' : '' }}">
                @error('phone')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="role">Role <span class="req">*</span></label>
                <select id="role" name="role" required class="{{ $errors->has('role') ? 'is-invalid' : '' }}">
                    <option value="">Select a role...</option>
                    @foreach ($roles as $key => $label)
                        <option value="{{ $key }}" {{ old('role') === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                @error('role')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-group">
                <label for="password">Password <span class="req">*</span></label>
                <input type="password" id="password" name="password" required
                       class="{{ $errors->has('password') ? 'is-invalid' : '' }}">
                @error('password')<span class="field-error">{{ $message }}</span>@enderror
                <small class="field-help">Minimum 8 characters. Share it securely with the staff member.</small>
            </div>

            <div class="form-group">
                <label for="password_confirmation">Confirm Password <span class="req">*</span></label>
                <input type="password" id="password_confirmation" name="password_confirmation" required>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create Account</button>
                <a href="{{ route('admin.users.index') }}" class="btn btn-clear">Cancel</a>
            </div>
        </form>
    </div>
@endsection

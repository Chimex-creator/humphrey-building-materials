@extends('layouts.app')

@section('title', 'Change Password — Humphrey Building Materials')

@section('content')
<section class="page-banner">
    <div class="container">
        <h1>Change Password</h1>
        <p>Update the password for your account.</p>
    </div>
</section>

<section class="section">
    <div class="container auth-wrap">
        <div class="auth-card auth-card-wide">

            @include('profile.partials.nav')

            <h2>Change Password</h2>

            <form action="{{ route('password.change.update') }}" method="POST" novalidate>
                @csrf

                <div class="form-group">
                    <label for="current_password">Current Password <span class="req">*</span></label>
                    <input type="password" id="current_password" name="current_password" required autofocus
                           class="{{ $errors->has('current_password') ? 'is-invalid' : '' }}">
                    @error('current_password')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="password">New Password <span class="req">*</span></label>
                    <input type="password" id="password" name="password" required
                           class="{{ $errors->has('password') ? 'is-invalid' : '' }}">
                    @error('password')<span class="field-error">{{ $message }}</span>@enderror
                    <small class="field-help">Minimum 8 characters.</small>
                </div>

                <div class="form-group">
                    <label for="password_confirmation">Confirm New Password <span class="req">*</span></label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Update Password</button>
            </form>
        </div>
    </div>
</section>
@endsection

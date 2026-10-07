@extends('layouts.app')

@section('title', 'Reset Password — Humphrey Building Materials')

@section('content')
<section class="page-banner">
    <div class="container">
        <h1>Choose a New Password</h1>
        <p>Your new password must be at least 8 characters.</p>
    </div>
</section>

<section class="section">
    <div class="container auth-wrap">
        <div class="auth-card">
            <h2>Reset Password</h2>

            <form action="{{ route('password.update') }}" method="POST" novalidate>
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <input type="hidden" name="email" value="{{ $email }}">

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="{{ $email }}" readonly>
                </div>

                <div class="form-group">
                    <label for="password">New Password <span class="req">*</span></label>
                    <input type="password" id="password" name="password" required autofocus
                           class="{{ $errors->has('password') ? 'is-invalid' : '' }}">
                    @error('password')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="password_confirmation">Confirm New Password <span class="req">*</span></label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Reset Password</button>
            </form>

            @error('email')<span class="field-error">{{ $message }}</span>@enderror
        </div>
    </div>
</section>
@endsection

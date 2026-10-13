@extends('layouts.app')

@section('title', 'Create an Account — Humphrey Building Materials')

@section('content')
<section class="page-banner">
    <div class="container">
        <h1>Create an Account</h1>
        <p>Join Humphrey Building Materials to place orders and track deliveries.</p>
    </div>
</section>

<section class="section">
    <div class="container auth-wrap">
        <div class="auth-card">
            <h2>Register</h2>
            <p class="auth-sub">Enter your details below — we'll email you a verification
                link, and your account is created once you click it.</p>

            <form action="{{ route('register') }}" method="POST" novalidate>
                @csrf

                <div class="form-group">
                    <label for="name">Full Name <span class="req">*</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus
                           class="{{ $errors->has('name') ? 'is-invalid' : '' }}">
                    @error('name')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="email">Email Address <span class="req">*</span></label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required
                           class="{{ $errors->has('email') ? 'is-invalid' : '' }}">
                    @error('email')<span class="field-error">{{ $message }}</span>@enderror
                    <small class="field-help">We'll send a verification link here — your account
                        is only created after you click it.</small>
                </div>

                <div class="form-group">
                    <label for="password">Password <span class="req">*</span></label>
                    <input type="password" id="password" name="password" required
                           class="{{ $errors->has('password') ? 'is-invalid' : '' }}">
                    @error('password')<span class="field-error">{{ $message }}</span>@enderror
                    <small class="field-help">Minimum 8 characters.</small>
                </div>

                <div class="form-group">
                    <label for="password_confirmation">Confirm Password <span class="req">*</span></label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Create Account</button>
            </form>

            @if (config('services.google.client_id'))
                <p class="auth-alt" aria-hidden="true">— or —</p>

                <a href="{{ route('google.redirect') }}" class="btn btn-google btn-block">
                    <svg class="google-g" viewBox="0 0 48 48" width="18" height="18"
                         aria-hidden="true" focusable="false">
                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                    </svg>
                    <span>Continue with Google</span>
                </a>
            @endif

            <p class="auth-alt">
                Already have an account? <a href="{{ route('login') }}">Log in</a>
            </p>
        </div>
    </div>
</section>
@endsection

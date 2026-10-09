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

                <a href="{{ route('google.redirect') }}" class="btn btn-outline-dark btn-block">
                    Continue with Google
                </a>
            @endif

            <p class="auth-alt">
                Already have an account? <a href="{{ route('login') }}">Log in</a>
            </p>
        </div>
    </div>
</section>
@endsection

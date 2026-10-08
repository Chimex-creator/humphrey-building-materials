@extends('layouts.app')

@section('title', 'Login — Humphrey Building Materials')

@section('content')
<section class="page-banner">
    <div class="container">
        <h1>Welcome Back</h1>
        <p>Log in to your Humphrey Building Materials account.</p>
    </div>
</section>

<section class="section">
    <div class="container auth-wrap">
        <div class="auth-card">
            <h2>Login</h2>

            <form action="{{ route('login') }}" method="POST" novalidate>
                @csrf

                <div class="form-group">
                    <label for="email">Email Address <span class="req">*</span></label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                           class="{{ $errors->has('email') ? 'is-invalid' : '' }}">
                    @error('email')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="password">Password <span class="req">*</span></label>
                    <input type="password" id="password" name="password" required
                           class="{{ $errors->has('password') ? 'is-invalid' : '' }}">
                    @error('password')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-check">
                    <input type="checkbox" id="remember" name="remember" value="1">
                    <label for="remember">Keep me logged in</label>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Log In</button>

                <p class="auth-alt">
                    <a href="{{ route('password.request') }}">Forgot your password?</a>
                </p>
            </form>

            @if (config('services.google.client_id'))
                <p class="auth-alt" aria-hidden="true">— or —</p>

                <a href="{{ route('google.redirect') }}" class="btn btn-outline-dark btn-block">
                    Continue with Google
                </a>
            @endif

            <p class="auth-alt">
                Don't have an account? <a href="{{ route('register') }}">Register</a>
            </p>
        </div>
    </div>
</section>
@endsection

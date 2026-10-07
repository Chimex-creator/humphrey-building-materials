@extends('layouts.app')

@section('title', 'Forgot Password — Humphrey Building Materials')

@section('content')
<section class="page-banner">
    <div class="container">
        <h1>Reset Your Password</h1>
        <p>We'll email you a secure link to choose a new password.</p>
    </div>
</section>

<section class="section">
    <div class="container auth-wrap">
        <div class="auth-card">
            <h2>Forgot Password</h2>
            <p class="auth-sub">Enter your registered email address below.</p>

            <form action="{{ route('password.request') }}" method="POST" novalidate>
                @csrf

                <div class="form-group">
                    <label for="email">Email Address <span class="req">*</span></label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                           class="{{ $errors->has('email') ? 'is-invalid' : '' }}">
                    @error('email')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
            </form>

            <p class="auth-alt">
                Remembered it? <a href="{{ route('login') }}">Back to login</a>
            </p>
        </div>
    </div>
</section>
@endsection

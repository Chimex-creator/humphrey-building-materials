@extends('layouts.app')

@section('title', $title . ' — Humphrey Building Materials')

@section('page', 'verification-result')

@section('content')
<section class="page-banner">
    <div class="container">
        <h1>Email Verification</h1>
        <p>{{ $title }}</p>
    </div>
</section>

<section class="section">
    <div class="container auth-wrap">
        <div class="auth-card auth-card-wide">
            <div class="verify-icon">⚠️</div>
            <h2>{{ $title }}</h2>

            <p class="auth-sub">{{ $message }}</p>

            <form action="{{ route('registration.resend') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label for="resend-email">Email Address</label>
                    <input type="email" id="resend-email" name="email" value="{{ $email ?? '' }}"
                           required maxlength="255" placeholder="you@example.com">
                </div>
                <button type="submit" class="btn btn-navy btn-block">Send A New Verification Link</button>
            </form>

            <p class="auth-alt">
                <a href="{{ route('login') }}">Log in</a> ·
                <a href="{{ route('register') }}">Register again</a> ·
                <a href="{{ route('home') }}">Back to home</a>
            </p>

            <p class="auth-sub">
                Questions? Call <a href="tel:+2348153667923">+2348153667923</a>.
            </p>
        </div>
    </div>
</section>
@endsection

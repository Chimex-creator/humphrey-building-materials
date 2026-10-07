@extends('layouts.app')

@section('title', 'Verify Your Email — Humphrey Building Materials')

@section('page', 'pending-verification')

@section('content')
<section class="page-banner">
    <div class="container">
        <h1>Verify Your Email</h1>
        <p>One more step to activate your account.</p>
    </div>
</section>

<section class="section">
    <div class="container auth-wrap">
        <div class="auth-card auth-card-wide">
            <div class="verify-icon">✉️</div>
            <h2>Check your inbox</h2>

            @if ($email)
                <p class="auth-sub">
                    We sent a verification link to <strong>{{ $email }}</strong>.
                    Click the link in that email to activate your account —
                    it stays valid for {{ \App\Models\PendingRegistration::EXPIRE_MINUTES }} minutes.
                </p>
            @else
                <p class="auth-sub">
                    We sent a verification link to the address you registered with.
                    Click the link in that email to activate your account.
                </p>
            @endif

            <p class="auth-sub">
                Your account is created as soon as you verify — until then you can simply
                wait for the email. Didn't get it? Check your spam folder, or resend below.
            </p>

            <form action="{{ route('registration.resend') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label for="resend-email">Email Address</label>
                    <input type="email" id="resend-email" name="email" value="{{ $email ?? '' }}"
                           required maxlength="255" placeholder="you@example.com">
                </div>
                <button type="submit" class="btn btn-navy btn-block">Resend Verification Email</button>
            </form>

            <p class="auth-alt">
                Wrong address? <a href="{{ route('register') }}">Register again</a> ·
                Already verified? <a href="{{ route('login') }}">Log in</a>
            </p>

            <p class="auth-sub">
                Need help? Call us on
                <a href="tel:+2348153667923">+2348153667923</a>
                or email <a href="mailto:humphreybuildingmaterials@gmail.com">humphreybuildingmaterials@gmail.com</a>.
            </p>
        </div>
    </div>
</section>
@endsection

@extends('layouts.app')

@section('title', 'Verify Your Email — Humphrey Building Materials')

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
            <p class="auth-sub">
                We sent a verification link to <strong>{{ auth()->user()->email }}</strong>.
                Click the link in that email to activate your account.
            </p>

            <form action="{{ route('verification.resend') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-navy btn-block">Resend Verification Email</button>
            </form>

            <div class="auth-alt">
                <a href="{{ route('login') }}">Log in</a> ·
                <form action="{{ route('logout') }}" method="POST" style="display:inline"
                      onsubmit="return confirm('Are you sure you want to log out?');">
                    @csrf
                    <button type="submit" class="link-button">Logout</button>
                </form>
            </div>
        </div>
    </div>
</section>
@endsection

@extends('layouts.auth')

@section('title', 'Reset password')

@section('auth-content')
<div class="login-content">
    <h1>Choose a new password</h1>
    <p class="muted">Set a new password for your Five CE Gas account.</p>
    @if ($errors->any())
        <div class="alert alert-error" role="alert">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('password.update') }}" class="form-stack">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label>Email address
            <span class="login-input-wrap">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
                <input type="email" name="email" value="{{ old('email', $email) }}" placeholder="Enter your account email" autocomplete="email" required autofocus>
            </span>
        </label>
        <label>New password
            <span class="login-input-wrap">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3M12 14v3"/></svg>
                <input type="password" name="password" placeholder="At least 10 characters" autocomplete="new-password" required>
            </span>
        </label>
        <label>Confirm new password
            <span class="login-input-wrap">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3M12 14v3"/></svg>
                <input type="password" name="password_confirmation" placeholder="Re-enter your new password" autocomplete="new-password" required>
            </span>
        </label>
        <button class="login-submit" type="submit">Reset password</button>
    </form>
    <a class="login-back-link" href="{{ route('login') }}">← Back to login</a>
</div>
@endsection

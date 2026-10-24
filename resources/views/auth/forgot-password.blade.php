@extends('layouts.auth')

@section('title', 'Forgot password')

@section('auth-content')
<div class="login-content">
    <h1>Forgot password?</h1>
    <p class="muted">Enter your account email address and we’ll send you a link to choose a new password.</p>
    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error" role="alert">{{ $errors->first('email') }}</div>
    @endif
    <form method="POST" action="{{ route('password.email') }}" class="form-stack">
        @csrf
        <label>Email address
            <span class="login-input-wrap">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
                <input type="email" name="email" value="{{ old('email') }}" placeholder="Enter your account email" autocomplete="email" required autofocus>
            </span>
        </label>
        <button class="login-submit" type="submit">Send password reset link</button>
    </form>
    <a class="login-back-link" href="{{ route('login') }}">← Back to login</a>
</div>
@endsection

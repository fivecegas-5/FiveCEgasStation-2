@extends('layouts.auth')

@section('title', 'Sign in')

@section('auth-content')
<div class="login-content">
    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif
    <h1>STAFF &amp; MANAGER LOGIN</h1>
    <p class="muted">Access the system to manage fuel inventory, sales, and branch operations.</p>
    @if ($errors->any())
        <div class="alert alert-error" role="alert">The email or password is incorrect. Please try again.</div>
    @endif
    <form method="POST" action="{{ route('login') }}" class="form-stack">
        @csrf
        <label>Email address
            <span class="login-input-wrap">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2z"/></svg>
                <input type="email" name="email" value="{{ old('email') }}" placeholder="Enter your email" autocomplete="username" required autofocus>
            </span>
        </label>
        <label>Password
            <span class="login-input-wrap">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3M12 14v3"/></svg>
                <input type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
            </span>
        </label>
        <div class="login-options">
            <label class="check-label"><input type="checkbox" name="remember" value="1"> Remember me</label>
            <a href="{{ route('password.request') }}">Forgot password?</a>
        </div>
        <button class="login-submit" type="submit">Log in</button>
    </form>
</div>
@endsection

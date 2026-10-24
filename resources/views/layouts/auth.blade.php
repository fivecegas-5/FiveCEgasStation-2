<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sign in') · Five CE Gas</title>
    <link rel="stylesheet" href="{{ asset('css/fivece.css') }}">
</head>
<body class="auth-shell">
<main class="login-layout">
    <aside class="login-aside login-photo-panel">
        <div class="login-photo-shade"></div>
        <div class="login-photo-message">
            <h1>Fueling<br>A Better<br>Tomorrow</h1>
            <div class="login-photo-brand">
                <img src="{{ asset('images/fiveCElogo.png') }}" alt="">
                <span>FIVE C E GAS</span>
            </div>
        </div>
    </aside>
    <section class="login-panel">
        <div class="login-panel-content">
            <a class="login-station-brand" href="{{ route('login') }}">
                <img src="{{ asset('images/fiveCElogo.png') }}" alt="Five CE Gas logo">
                <strong>FIVE <span>C E</span> GAS</strong>
            </a>
            @yield('auth-content')
            <div class="login-security">
                <p>Only authorized personnel can access<br>the operations dashboard.</p>
                <span class="security-shield" aria-hidden="true">
                    <svg viewBox="0 0 64 72"><path d="M32 2 60 14v20c0 17-11 29-28 36C15 63 4 51 4 34V14z" fill="currentColor"/><rect x="20" y="31" width="24" height="21" rx="4" fill="#172d55"/><path d="M25 31v-7a7 7 0 0 1 14 0v7" fill="none" stroke="#172d55" stroke-width="5" stroke-linecap="round"/><circle cx="32" cy="40" r="2.5" fill="#4d74bd"/><path d="M32 42v4" stroke="#4d74bd" stroke-width="2.5" stroke-linecap="round"/></svg>
                </span>
            </div>
            <footer class="login-copyright">© {{ now()->year }} Five CE Gas Station · FiveCETrack</footer>
        </div>
    </section>
</main>
</body>
</html>

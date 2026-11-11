<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sign in') · Five CE Gas</title>
</head>
<body>
    <div
        id="react-app-root"
        data-page="@yield('react-page', 'login')"
        data-authenticated="false"
        data-status="{{ session('status', '') }}"
        data-error="{{ $errors->first() }}"
        data-reset-token="@yield('reset-token', '')"
        data-email="@yield('auth-email', old('email', ''))"
    ></div>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</body>
</html>

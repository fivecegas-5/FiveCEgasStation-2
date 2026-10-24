<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'FiveCETrack') · Five CE Gas</title>
    <link rel="stylesheet" href="{{ asset('css/fivece.css') }}">
</head>
<body class="@auth app-shell @else auth-shell @endauth">
@auth
    <aside class="sidebar">
        <a class="brand" href="{{ route('dashboard') }}">
            <img class="brand-logo" src="{{ asset('images/fiveCElogo.png') }}" alt="Five CE Gas logo">
            <span class="brand-copy"><strong>FIVE C E GAS</strong><small>FUEL OPERATIONS</small></span>
        </a>
        <div class="branch-indicator">
            <span class="live-dot"></span>
            <span>{{ auth()->user()->isOwner() ? 'All branches' : (auth()->user()->branch?->name ?? 'Branch not assigned') }}</span>
        </div>
        <nav class="side-nav" aria-label="Main navigation">
            <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-6v-7h-4v7H4a1 1 0 0 1-1-1z"/></svg>
                <span>Dashboard</span>
            </a>
            <a class="nav-link {{ request()->routeIs('prices.*') ? 'active' : '' }}" href="{{ route('prices.index') }}">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 21V4a2 2 0 0 1 2-2h8v19M5 6h10M15 6h3l3 4v11h-6M8 10h4M8 14h4M8 18h4"/></svg>
                <span>Fuel Prices</span>
            </a>
            <a class="nav-link" href="{{ route('dashboard') }}#fuel-inventory">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 3V2h6v1M8 8h8M8 12h8M8 16h5"/></svg>
                <span>Fuel Inventory</span>
            </a>
            <a class="nav-link {{ request()->routeIs('deliveries.*') ? 'active' : '' }}" href="{{ auth()->user()->isOwner() ? (request('branch_id') ? route('deliveries.index', ['branch_id' => request('branch_id')]) : route('dashboard').'#branch-stock-in') : route('deliveries.index') }}">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 9h18M12 12v6M9 15l3 3 3-3"/></svg>
                <span>Stock-In</span>
            </a>
            <a class="nav-link {{ request()->routeIs('sales.*') ? 'active' : '' }}" href="{{ auth()->user()->isOwner() ? (request('branch_id') ? route('sales.index', ['branch_id' => request('branch_id')]) : route('dashboard').'#branch-stock-out') : route('sales.index') }}">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h10l6 8-6 8H4z"/><path d="m11 8-3 4h4l-2 4 5-6h-4l2-2"/></svg>
                <span>Stock-Out</span>
            </a>
            <a class="nav-link" href="{{ route('dashboard') }}#transactions">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12l2 2 4-4M8 17h8"/></svg>
                <span>Transactions</span>
            </a>
            <span class="nav-link nav-link-disabled" aria-disabled="true" title="Reports are not available yet">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3H4a1 1 0 0 0-1 1v17h18V4a1 1 0 0 0-1-1h-2M8 2h8v4H8zM7 11h10M7 15h10M7 19h6"/></svg>
                <span>Reports</span><small class="nav-coming-soon">Soon</small>
            </span>
            @if (in_array(auth()->user()->role->value, ['owner', 'manager'], true))
                <a class="nav-link {{ request()->routeIs('setup.*', 'users.*') ? 'active' : '' }}" href="{{ route('setup.index', auth()->user()->isOwner() && request('branch_id') ? ['branch_id' => request('branch_id')] : []) }}">
                    <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.6a8 8 0 0 1-1.5.9l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.5-.9l-1.7.6-1.4-2.4 1.4-1.1a7 7 0 0 1 0-1.8l-1.4-1.1 1.4-2.4 1.7.6a8 8 0 0 1 1.5-.9l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.5.9l1.7-.6 1.4 2.4-1.4 1.1a7 7 0 0 1 0 1.7Z"/></svg>
                    <span>Setting</span>
                </a>
            @endif
        </nav>
        <div class="sidebar-bottom">
            <div class="sidebar-profile">
                <span class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                <span class="profile-copy"><strong>{{ auth()->user()->name }}</strong><small>{{ ucfirst(auth()->user()->role->value) }}</small></span>
            </div>
            <form class="sidebar-logout" method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="nav-link" type="submit">
                    <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M21 4v16a1 1 0 0 1-1 1h-7"/></svg>
                    <span>Logout</span>
                </button>
            </form>
        </div>
    </aside>
    <main class="main-panel">
        <header class="topbar">
            <div class="breadcrumbs"><span>Five CE Gas Station</span><b>/</b><strong>@yield('section', 'Overview')</strong></div>
            <div class="topbar-right">
                <span class="today-label">{{ now()->format('D, M j, Y') }}</span>
                @if (auth()->user()->isOwner() && request()->routeIs('sales.*', 'deliveries.*', 'adjustments.*'))
                    <form class="branch-switch" method="GET" action="{{ url()->current() }}"><label for="branch-switch">Branch</label><select id="branch-switch" name="branch_id" onchange="this.form.submit()"><option value="">Choose branch</option>@foreach (\App\Models\Branch::where('is_active', true)->orderBy('name')->get() as $branch)<option value="{{ $branch->id }}" @selected((string) request('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>@endforeach</select></form>
                @endif
                <span class="top-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
            </div>
        </header>
        <div class="page-content">
            @if (session('success'))
                <div class="alert alert-success" role="status"><span>✓</span>{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="alert alert-error" role="alert"><span>!</span><div><strong>Please check the form.</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>
            @endif
            @yield('content')
        </div>
    </main>
@else
    @yield('content')
@endauth
<script>
    const syncAnchorNavigation = () => {
        if (!window.location.hash) return;
        const selectedLink = Array.from(document.querySelectorAll('.side-nav a.nav-link'))
            .find((link) => link.hash === window.location.hash);
        if (!selectedLink) return;
        document.querySelectorAll('.side-nav a.nav-link').forEach((link) => link.classList.remove('active'));
        selectedLink.classList.add('active');
    };
    window.addEventListener('DOMContentLoaded', syncAnchorNavigation);
    window.addEventListener('hashchange', syncAnchorNavigation);
</script>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'FiveCETrack') · Five CE Gas</title>
</head>
<body>
    @php($uiBranches = \App\Models\Branch::visibleTo(auth()->user())->where('is_active', true)->orderBy('name')->get(['id', 'name']))
    @php($activeUiBranch = $uiBranches->firstWhere('id', (int) session('active_branch_id')) ?? auth()->user()->branch)
    <div
        id="react-app-root"
        data-page="@yield('react-page', 'dashboard')"
        data-authenticated="true"
        data-is-owner="{{ auth()->user()->isOwner() ? 'true' : 'false' }}"
        data-can-switch-branch="{{ auth()->user()->isOwner() && auth()->user()->branch_id === null ? 'true' : 'false' }}"
        data-user-name="{{ auth()->user()->name }}"
        data-role="{{ auth()->user()->role->value }}"
        data-branch-name="{{ $activeUiBranch?->name ?? 'All branches' }}"
        data-active-branch-id="{{ session('active_branch_id', auth()->user()->branch_id) }}"
        data-branch-options="{{ $uiBranches->map(fn ($branch) => ['id' => $branch->id, 'name' => $branch->name])->values()->toJson() }}"
        data-status="{{ session('success', '') }}"
        data-error="{{ $errors->first() }}"
        data-setup-data="@yield('react-data', '{}')"
        data-workspace-url="{{ route('api.workspace') }}"
        data-batches-url="{{ route('api.sales.import-batches.index') }}"
        data-preview-url="{{ route('api.sales.import.preview') }}"
        data-template-url="{{ route('api.sales.import.template') }}"
        data-batch-url-template="{{ route('api.sales.import-batches.show', ['batch' => '__BATCH_ID__']) }}"
        data-credit-customers-url="{{ route('api.credit.customers.index') }}"
        data-credit-agreements-url="{{ route('api.credit.agreements.index') }}"
        data-credit-payments-url="{{ url('/api/credit/payments') }}"
    ></div>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</body>
</html>

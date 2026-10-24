@extends('layouts.app')

@section('title', 'User management')
@section('section', 'User management')

@section('content')
<div class="page-heading"><div><div class="eyebrow">TEAM ACCESS</div><h1>User management</h1><p>Give each team member access only to the branch they work at.</p></div></div>
<div class="operations-grid">
    <section class="panel form-panel"><div class="panel-heading"><div><h2>Create an account</h2><p>Accounts are created by an owner or branch manager</p></div><span class="panel-step">＋</span></div>
        <form method="POST" action="{{ route('users.store') }}" class="form-stack">
            @csrf
            <label>Full name<input name="name" value="{{ old('name') }}" maxlength="255" placeholder="Team member name" required></label>
            <label>Work email<input type="email" name="email" value="{{ old('email') }}" maxlength="255" placeholder="name@fivecegas.com" required></label>
            <div class="form-row"><label>Access role<select name="role" required><option value="staff" @selected(old('role') === 'staff')>Staff · record station activity</option>@if ($canCreateManager)<option value="manager" @selected(old('role') === 'manager')>Manager · review branch activity</option><option value="owner" @selected(old('role') === 'owner')>Owner · access both branches</option>@endif</select></label>
            @if (auth()->user()->isOwner())<label>Branch<select name="branch_id"><option value="">No branch (owner account)</option>@foreach ($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>@else<div class="branch-locked"><small>ASSIGNED BRANCH</small><strong>{{ auth()->user()->branch?->name ?? 'Not assigned' }}</strong><span>New staff are assigned to your branch.</span></div>@endif</div>
            <div class="form-row"><label>Password<input type="password" name="password" minlength="10" autocomplete="new-password" placeholder="At least 10 characters" required></label><label>Confirm password<input type="password" name="password_confirmation" minlength="10" autocomplete="new-password" placeholder="Re-enter password" required></label></div>
            <div class="form-hint"><span>i</span> Share the temporary password securely with the team member.</div>
            <button class="button button-primary button-wide" type="submit">Create team account <span>→</span></button>
        </form>
    </section>
    <aside class="panel side-note"><div class="note-icon">♙</div><span class="eyebrow">BRANCH-LEVEL ACCESS</span><h3>Right people. Right branch.</h3><p>Managers review their own branch. Staff record sales and request adjustments. Owners can view operations across both locations.</p><div class="note-divider"></div><div class="role-key"><span class="role-owner">O</span><span><strong>Owner</strong><small>Both branches and account management</small></span></div><div class="role-key"><span class="role-manager">M</span><span><strong>Manager</strong><small>Branch operations and approvals</small></span></div><div class="role-key"><span class="role-staff">S</span><span><strong>Staff</strong><small>Branch sales and stock recording</small></span></div></aside>
</div>
<section class="panel table-panel"><div class="panel-heading"><div><h2>Team members</h2><p>{{ $users->count() }} registered {{ \Illuminate\Support\Str::plural('account', $users->count()) }}</p></div></div>
    @if ($users->isEmpty())<div class="table-empty"><span>♙</span><strong>No accounts found</strong><p>New accounts will appear here.</p></div>@else<div class="table-scroll"><table><thead><tr><th>TEAM MEMBER</th><th>EMAIL</th><th>BRANCH</th><th>ROLE</th><th>JOINED</th></tr></thead><tbody>
    @foreach ($users as $teamMember)<tr><td><span class="user-cell"><span class="avatar avatar-small">{{ strtoupper(substr($teamMember->name, 0, 1)) }}</span><strong>{{ $teamMember->name }}</strong>@if ($teamMember->is(auth()->user()))<span class="you-badge">YOU</span>@endif</span></td><td>{{ $teamMember->email }}</td><td>{{ $teamMember->branch?->name ?? 'All branches' }}</td><td><span class="role-pill role-pill-{{ $teamMember->role->value }}">{{ ucfirst($teamMember->role->value) }}</span></td><td>{{ $teamMember->created_at->format('M j, Y') }}</td></tr>@endforeach
    </tbody></table></div>@endif
</section>
@endsection

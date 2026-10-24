@extends('layouts.app')

@section('title', 'Stock adjustments')
@section('section', 'Adjustments')

@section('content')
<div class="page-heading"><div><div class="eyebrow">VARIANCE CONTROL</div><h1>Stock adjustments</h1><p>Report a mismatch; a manager reviews each change before it affects stock.</p></div></div>
<div class="operations-grid">
    <section class="panel form-panel"><div class="panel-heading"><div><h2>Report a discrepancy</h2><p>Explain what was found and request a stock correction</p></div><span class="panel-step">!</span></div>
        @if ($tanks->isEmpty())<div class="empty-inline">Set up a fuel tank before reporting a discrepancy. <a href="{{ route('setup.index', auth()->user()->isOwner() ? ['branch_id' => $branchId] : []) }}">Station setup →</a></div>@else
        <form method="POST" action="{{ route('adjustments.store', auth()->user()->isOwner() ? ['branch_id' => $branchId] : []) }}" class="form-stack">
            @csrf
            <label>Tank<select name="fuel_tank_id" required><option value="">Select a tank</option>@foreach ($tanks as $tank)<option value="{{ $tank->id }}" @selected(old('fuel_tank_id') == $tank->id)>{{ $tank->code }} · {{ $tank->fuelProduct->name }}</option>@endforeach</select></label>
            <label>Stock change (liters)<input name="quantity_change_liters" type="number" step="0.001" min="-100000" max="100000" value="{{ old('quantity_change_liters') }}" placeholder="Use a minus sign for a stock loss" required><small>Positive adds stock; negative records a loss.</small></label>
            <label>Reason for adjustment<textarea name="reason" rows="4" maxlength="2000" placeholder="Describe the observed discrepancy and how it was checked." required>{{ old('reason') }}</textarea></label>
            <div class="form-hint"><span>i</span> Your request does not change stock until approved.</div>
            <button class="button button-primary button-wide" type="submit">Send for manager review <span>→</span></button>
        </form>@endif
    </section>
    <aside class="panel side-note"><div class="note-icon note-red">↔</div><span class="eyebrow">AUDITABLE CORRECTIONS</span><h3>Original records stay untouched.</h3><p>Variances are captured as separate requests. After approval, the adjustment is appended to the inventory movement ledger with the reviewer and timestamp.</p><div class="note-divider"></div><div class="note-line"><span class="note-check">✓</span><span>Reason kept with the request</span></div><div class="note-line"><span class="note-check">✓</span><span>Manager approval required</span></div><div class="note-line"><span class="note-check">✓</span><span>Append-only stock movement history</span></div></aside>
</div>
<section class="panel table-panel"><div class="panel-heading"><div><h2>Adjustment requests</h2><p>Review status for recent stock discrepancies</p></div></div>
    @if ($adjustments->isEmpty())<div class="table-empty"><span>↔</span><strong>No adjustment requests</strong><p>Requests from your team will appear here.</p></div>@else
    <div class="table-scroll"><table><thead><tr><th>TANK</th><th>REPORTED BY</th><th>REASON</th><th>CHANGE</th><th>REQUESTED</th><th>STATUS</th>@if ($canApprove)<th>ACTION</th>@endif</tr></thead><tbody>
    @foreach ($adjustments as $adjustment)<tr><td><strong>{{ $adjustment->tank->code }}</strong><small class="subline">{{ $adjustment->tank->fuelProduct->name }}</small></td><td>{{ $adjustment->requester->name }}</td><td class="reason-cell">{{ $adjustment->reason }}</td><td><strong class="{{ (float) $adjustment->quantity_change_liters < 0 ? 'text-red' : 'text-green' }}">{{ (float) $adjustment->quantity_change_liters > 0 ? '+' : '' }}{{ number_format((float) $adjustment->quantity_change_liters, 3) }} L</strong></td><td>{{ $adjustment->created_at->format('M j, g:i A') }}</td><td><span class="status-badge status-{{ $adjustment->status }}">{{ ucfirst($adjustment->status) }}</span></td>@if ($canApprove)<td>@if ($adjustment->status === 'pending')<form method="POST" action="{{ route('adjustments.approve', $adjustment) }}">@csrf<button class="button button-small button-primary" type="submit">Approve</button></form>@else<span class="muted">{{ $adjustment->reviewer?->name ?? 'Reviewed' }}</span>@endif</td>@endif</tr>@endforeach
    </tbody></table></div>@endif
</section>
@endsection

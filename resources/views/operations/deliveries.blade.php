@extends('layouts.app')

@section('title', 'Deliveries')
@section('section', 'Deliveries')

@section('content')
<div class="page-heading"><div><div class="eyebrow">STOCK IN</div><h1>Fuel deliveries</h1><p>Log received fuel and add it to stock after a manager review.</p></div></div>
<div class="operations-grid">
    <section class="panel form-panel"><div class="panel-heading"><div><h2>Record a delivery</h2><p>New deliveries are pending until approved</p></div><span class="panel-step">IN</span></div>
        @if ($tanks->isEmpty())<div class="empty-inline">Set up a tank before recording a delivery. <a href="{{ route('setup.index', auth()->user()->isOwner() ? ['branch_id' => $branchId] : []) }}">Station setup →</a></div>@else
        <form method="POST" action="{{ route('deliveries.store', auth()->user()->isOwner() ? ['branch_id' => $branchId] : []) }}" class="form-stack">
            @csrf
            <label>Receiving tank<select name="fuel_tank_id" required><option value="">Select a tank</option>@foreach ($tanks as $tank)<option value="{{ $tank->id }}" @selected(old('fuel_tank_id') == $tank->id)>{{ $tank->code }} · {{ $tank->fuelProduct->name }}</option>@endforeach</select></label>
            <div class="form-row"><label>Quantity delivered (L)<input name="delivered_liters" type="number" min="0.001" step="0.001" value="{{ old('delivered_liters') }}" placeholder="0.000" required></label><label>Purchase cost per liter<input name="cost_per_liter" type="number" min="0" step="0.01" value="{{ old('cost_per_liter') }}" placeholder="0.00" required></label></div>
            <div class="form-row"><label>Supplier <span class="optional">OPTIONAL</span><input name="supplier_name" value="{{ old('supplier_name') }}" maxlength="255" placeholder="Supplier name"></label><label>Delivery reference <span class="optional">OPTIONAL</span><input name="delivery_reference" value="{{ old('delivery_reference') }}" maxlength="100" placeholder="Invoice or delivery no."></label></div>
            <label>Received at<input type="datetime-local" name="received_at" value="{{ old('received_at', now()->format('Y-m-d\TH:i')) }}" max="{{ now()->format('Y-m-d\TH:i') }}" required></label>
            <div class="form-hint"><span>i</span> Stock updates only after manager approval.</div>
            <button class="button button-primary button-wide" type="submit">Submit delivery for review <span>→</span></button>
        </form>@endif
    </section>
    <aside class="panel side-note"><div class="note-icon note-orange">↓</div><span class="eyebrow">CONTROLLED STOCK IN</span><h3>Received first. Counted after review.</h3><p>Every delivery starts as pending. A manager verifies the quantity and cost before stock is updated in the ledger.</p><div class="note-divider"></div><div class="note-line"><span class="note-check">✓</span><span>Purchase cost saved per liter</span></div><div class="note-line"><span class="note-check">✓</span><span>Approval recorded with reviewer</span></div><div class="note-line"><span class="note-check">✓</span><span>No change to stock before approval</span></div></aside>
</div>
<section class="panel table-panel"><div class="panel-heading"><div><h2>Delivery records</h2><p>Most recent stock-in activity</p></div></div>
    @if ($deliveries->isEmpty())<div class="table-empty"><span>↓</span><strong>No deliveries recorded</strong><p>Submitted deliveries will be listed here.</p></div>@else
    <div class="table-scroll"><table><thead><tr><th>DELIVERY</th><th>TANK / FUEL</th><th>RECORDED BY</th><th>RECEIVED</th><th>QUANTITY</th><th>TOTAL COST</th><th>STATUS</th>@if ($canApprove)<th>ACTION</th>@endif</tr></thead><tbody>
    @foreach ($deliveries as $delivery)<tr><td><strong>{{ $delivery->delivery_reference ?: '—' }}</strong><small class="subline">{{ $delivery->supplier_name ?: 'Supplier not specified' }}</small></td><td>{{ $delivery->tank->code }} · {{ $delivery->tank->fuelProduct->name }}</td><td>{{ $delivery->recorder->name }}</td><td>{{ $delivery->received_at->format('M j, g:i A') }}</td><td>{{ number_format((float) $delivery->delivered_liters, 2) }} L</td><td>₱{{ number_format((float) $delivery->total_cost, 2) }}</td><td><span class="status-badge status-{{ $delivery->status }}">{{ ucfirst($delivery->status) }}</span></td>@if ($canApprove)<td>@if ($delivery->status === 'pending')<form method="POST" action="{{ route('deliveries.approve', $delivery) }}">@csrf<button class="button button-small button-primary" type="submit">Approve</button></form>@else<span class="muted">{{ $delivery->reviewer?->name ?? 'Reviewed' }}</span>@endif</td>@endif</tr>@endforeach
    </tbody></table></div>@endif
</section>
@endsection

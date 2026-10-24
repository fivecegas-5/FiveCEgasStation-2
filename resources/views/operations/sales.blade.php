@extends('layouts.app')

@section('title', 'Fuel sales')
@section('section', 'Fuel sales')

@section('content')
<div class="page-heading heading-with-action"><div><div class="eyebrow">CUSTOMER TRANSACTIONS</div><h1>Fuel sales</h1><p>Record every dispense at the price active at the time of sale.</p></div><span class="branch-pill"><i class="status-dot"></i> {{ auth()->user()->isOwner() ? \App\Models\Branch::find($branchId)?->name : auth()->user()->branch?->name }}</span></div>
<div class="operations-grid">
    <section class="panel form-panel"><div class="panel-heading"><div><h2>Record a sale</h2><p>Enter the fuel dispensed for this customer</p></div><span class="panel-step">01</span></div>
        @if ($nozzles->isEmpty())<div class="empty-inline">No pump nozzles configured for this branch yet. <a href="{{ route('setup.index', ['branch_id' => $branchId]) }}">Set up the station →</a></div>@else
        <form method="POST" action="{{ route('sales.store', auth()->user()->isOwner() ? ['branch_id' => $branchId] : []) }}" class="form-stack">
            @csrf
            <label>Fuel pump &amp; nozzle
                <select name="fuel_nozzle_id" id="sale-nozzle" required>
                    <option value="">Select a nozzle</option>
                    @foreach ($nozzles as $nozzle)
                        @php($price = $prices[$nozzle->tank->fuel_product_id] ?? null)
                        <option value="{{ $nozzle->id }}" data-price="{{ $price?->price_per_liter }}" @selected(old('fuel_nozzle_id') == $nozzle->id)>
                            {{ $nozzle->pump->code }} / {{ $nozzle->code }} · {{ $nozzle->tank->fuelProduct->name }} · {{ $price ? '₱'.number_format((float) $price->price_per_liter, 2).'/L' : 'Price not set' }}
                        </option>
                    @endforeach
                </select>
            </label>
            <div class="form-row"><label>Liters dispensed<input id="sale-liters" name="liters" type="number" min="0.001" max="100000" step="0.001" value="{{ old('liters') }}" placeholder="0.000" required><small>Enter the liters shown by the pump.</small></label><label>Sale time<input type="datetime-local" name="sold_at" value="{{ old('sold_at', now()->format('Y-m-d\TH:i')) }}" max="{{ now()->format('Y-m-d\TH:i') }}" required></label></div>
            <div class="form-row"><label>Receipt number <span class="optional">OPTIONAL</span><input name="receipt_number" value="{{ old('receipt_number') }}" maxlength="100" placeholder="e.g. RCPT-0012"></label><label>Payment method <span class="optional">OPTIONAL</span><select name="payment_method"><option value="">Select payment type</option><option value="cash">Cash</option><option value="card">Card</option><option value="ewallet">E-wallet</option><option value="other">Other</option></select></label></div>
            <div class="sale-total"><span><small>CALCULATED SALE TOTAL</small><strong id="sale-total">₱0.00</strong></span><span class="total-note">Saved with the current fuel price</span></div>
            <button class="button button-primary button-wide" type="submit">Save sale <span>→</span></button>
        </form>@endif
    </section>
    <aside class="panel side-note"><div class="note-icon">✓</div><span class="eyebrow">ACCURATE BY DESIGN</span><h3>A clear record for every dispense.</h3><p>Sale total is calculated using this branch’s active price. The price is saved on the transaction, so later price changes won’t affect old sales.</p><div class="note-divider"></div><div class="note-line"><span class="note-check">✓</span><span>Liters and price captured together</span></div><div class="note-line"><span class="note-check">✓</span><span>Stock automatically reduced</span></div><div class="note-line"><span class="note-check">✓</span><span>Staff member and time recorded</span></div></aside>
</div>
<section class="panel table-panel"><div class="panel-heading"><div><h2>Recent transactions</h2><p>Latest sales for this branch</p></div><span class="count-pill">{{ $sales->count() }} records</span></div>
    @if ($sales->isEmpty())<div class="table-empty"><span>₱</span><strong>No transactions recorded yet</strong><p>Your saved sales will be listed here.</p></div>
    @else<div class="table-scroll"><table><thead><tr><th>RECEIPT</th><th>FUEL / NOZZLE</th><th>RECORDED BY</th><th>SALE TIME</th><th>LITERS</th><th>PRICE/L</th><th>TOTAL</th></tr></thead><tbody>
    @foreach ($sales as $sale)<tr><td>{{ $sale->receipt_number ?: '—' }}</td><td><strong>{{ $sale->fuelProduct->name }}</strong><small class="subline">{{ $sale->nozzle->pump->code }} / {{ $sale->nozzle->code }}</small></td><td>{{ $sale->recorder->name }}</td><td>{{ $sale->sold_at->format('M j, g:i A') }}</td><td>{{ number_format((float) $sale->liters, 3) }} L</td><td>₱{{ number_format((float) $sale->price_per_liter, 2) }}</td><td><strong>₱{{ number_format((float) $sale->total_amount, 2) }}</strong></td></tr>@endforeach
    </tbody></table></div>@endif
</section>
<script>
(() => {
    const nozzle = document.getElementById('sale-nozzle');
    const liters = document.getElementById('sale-liters');
    const total = document.getElementById('sale-total');
    if (!nozzle || !liters || !total) return;
    const update = () => {
        const price = Number(nozzle.selectedOptions[0]?.dataset.price || 0);
        const amount = Number(liters.value || 0) * price;
        total.textContent = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(amount);
    };
    nozzle.addEventListener('change', update);
    liters.addEventListener('input', update);
    update();
})();
</script>
@endsection

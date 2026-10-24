@extends('layouts.app')

@section('title', 'Dashboard')
@section('section', 'Dashboard')

@section('content')
<div class="page-heading heading-with-action dashboard-heading">
    <div>
        <div class="eyebrow">FIVE CE GAS STATION</div>
        <h1>Dashboard</h1>
        <p>Welcome back, {{ auth()->user()->isOwner() ? 'Owner' : auth()->user()->name }}. Here’s your latest fuel inventory.</p>
    </div>
    @if ($canManage)
        <a class="button button-primary" href="{{ route('setup.index') }}"><span>＋</span> Manage fuel setup</a>
    @endif
</div>

<section class="dashboard-fuel-cards" id="fuel-inventory" aria-label="Fuel inventory summary">
    @forelse ($inventoryByProduct as $index => $inventory)
        <article class="dashboard-fuel-card fuel-card-{{ $index % 3 }}">
            <div class="dashboard-fuel-icon" aria-hidden="true"><span>▰</span><i></i></div>
            <div class="dashboard-fuel-copy">
                <span class="fuel-card-branch">
                    {{ $inventory['branch_count'] > 1 ? 'ALL BRANCHES' : ($tanks->firstWhere('fuel_product_id', $inventory['product']->id)?->branch->name ?? 'BRANCH') }}
                </span>
                <h2>{{ $inventory['product']->name }}</h2>
                <strong>{{ number_format($inventory['stock'], 0) }} <small>L</small></strong>
                <span class="fuel-status {{ $inventory['stock'] > 0 ? 'fuel-status-in-stock' : 'fuel-status-empty' }}">
                    <i></i>{{ $inventory['stock'] > 0 ? 'In Stock' : 'Out of Stock' }}
                </span>
            </div>
            <div class="fuel-capacity-note">
                <span>{{ $inventory['tank_count'] }} {{ \Illuminate\Support\Str::plural('tank', $inventory['tank_count']) }}</span>
                <span>Capacity {{ number_format($inventory['capacity'], 0) }} L</span>
            </div>
        </article>
    @empty
        <div class="dashboard-empty-fuel">
            <span class="empty-icon">◉</span>
            <div><strong>No fuel inventory yet</strong><p>Set up fuel products and tanks to see stock summaries here.</p></div>
            @if ($canManage)<a class="button button-primary" href="{{ route('setup.index') }}">Set up station</a>@endif
        </div>
    @endforelse
</section>

<div class="dashboard-content-grid">
    <section class="panel dashboard-chart-panel">
        <div class="panel-heading dashboard-panel-heading">
            <div><h2>Fuel Inventory Level</h2><p>Recorded stock compared with tank capacity</p></div>
            <span class="chart-period"><i class="status-dot"></i> Live stock ledger</span>
        </div>
        @if ($chartTanks->isNotEmpty())
            <div class="chart-legend">
                <span><i class="legend-current"></i> Current stock</span>
                <span><i class="legend-capacity"></i> Tank capacity</span>
            </div>
            <div class="fuel-chart" role="img" aria-label="Fuel tank current stock compared with capacity">
                <div class="chart-scale" aria-hidden="true">
                    <span>100%</span><span>75%</span><span>50%</span><span>25%</span><span>0</span>
                </div>
                <div class="chart-main">
                    <div class="chart-grid-lines" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div>
                    <div class="chart-columns">
                        @foreach ($chartTanks as $tank)
                            <div class="chart-column" title="{{ $tank['branch'] }} · {{ $tank['label'] }} · {{ number_format($tank['stock'], 0) }} of {{ number_format($tank['capacity'], 0) }} liters">
                                <div class="chart-bars">
                                    <span class="chart-bar chart-bar-stock" style="height: {{ $tank['stock_percentage'] }}%"></span>
                                    <span class="chart-bar chart-bar-capacity" style="height: 100%"></span>
                                </div>
                                <strong>{{ $tank['label'] }}</strong>
                                <small>{{ $tank['fuel'] }}@if (auth()->user()->isOwner())<br>{{ $tank['branch'] }}@endif</small>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="chart-footnote">Current balances are calculated from recorded opening stock, approved deliveries, sales, and approved adjustments.</div>
        @else
            <div class="table-empty dashboard-chart-empty"><span>▥</span><strong>Inventory chart will appear here</strong><p>Once tanks and opening stock are set up, you can compare their current recorded balance with capacity.</p></div>
        @endif
    </section>

    <section class="panel dashboard-transactions-panel" id="transactions">
        <div class="panel-heading dashboard-panel-heading">
            <div><h2>Recent Transactions</h2><p>Latest recorded stock movements</p></div>
            @unless (auth()->user()->isOwner())<a href="{{ route('deliveries.index') }}">View all <span>→</span></a>@endunless
        </div>
        @if ($recentTransactions->isEmpty())
            <div class="table-empty dashboard-transactions-empty"><span>▤</span><strong>No stock transactions yet</strong><p>Sales, deliveries, and approved adjustments will appear here.</p></div>
        @else
            <div class="table-scroll dashboard-table-scroll">
                <table class="dashboard-transactions-table">
                    <thead><tr><th>DATE</th><th>TYPE</th><th>FUEL</th><th>LITERS</th><th>AMOUNT</th></tr></thead>
                    <tbody>
                    @foreach ($recentTransactions as $transaction)
                        @php($movement = $transaction['movement'])
                        <tr>
                            <td>{{ $movement->occurred_at->format('M j') }}<small class="dashboard-time">{{ $movement->occurred_at->format('g:i A') }}</small></td>
                            <td><span class="movement-type movement-type-{{ $transaction['direction'] }}">{{ $transaction['type'] }}</span></td>
                            <td><strong>{{ $movement->tank->fuelProduct->name }}</strong>@if (auth()->user()->isOwner())<small class="dashboard-time">{{ $movement->branch->name }}</small>@endif</td>
                            <td class="{{ $transaction['direction'] === 'out' ? 'movement-out' : 'movement-in' }}">{{ $transaction['direction'] === 'out' ? '−' : '+' }}{{ number_format(abs((float) $movement->signed_quantity_liters), 1) }} L</td>
                            <td>{{ $transaction['amount'] !== null ? '₱'.number_format($transaction['amount'], 2) : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="dashboard-transactions-foot">
                <span><i class="status-dot"></i> {{ $recentTransactions->count() }} latest movements</span>
                @unless (auth()->user()->isOwner())<a href="{{ route('sales.index') }}">Sales records →</a>@endunless
            </div>
        @endif
    </section>
</div>

<div class="dashboard-bottom-actions" @if (auth()->user()->isOwner()) id="branch-operations" @endif>
    <span><i class="status-dot"></i> Stock balances reflect approved and recorded activity.</span>
    <div class="{{ auth()->user()->isOwner() ? 'owner-dashboard-actions' : '' }}">
        @if (auth()->user()->isOwner())
            <div class="owner-action-group" id="branch-stock-in">
                <strong>Stock-In</strong>
                @foreach ($branches as $branch)
                    <a class="button button-secondary" href="{{ route('deliveries.index', ['branch_id' => $branch->id]) }}">{{ $branch->name }}</a>
                @endforeach
            </div>
            <div class="owner-action-group" id="branch-stock-out">
                <strong>Stock-Out</strong>
                @foreach ($branches as $branch)
                    <a class="button button-secondary" href="{{ route('sales.index', ['branch_id' => $branch->id]) }}">{{ $branch->name }}</a>
                @endforeach
            </div>
        @else
            <a class="button button-secondary" href="{{ route('sales.index') }}">View sales</a>
            <a class="button button-secondary" href="{{ route('adjustments.index') }}">Review stock adjustments</a>
        @endif
    </div>
</div>
@endsection

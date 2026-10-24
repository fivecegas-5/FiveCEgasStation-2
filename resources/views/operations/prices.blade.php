@extends('layouts.app')

@section('title', 'Fuel Prices')
@section('section', 'Fuel Prices')

@section('content')
<div class="page-heading heading-with-action">
    <div>
        <div class="eyebrow">BRANCH PRICING</div>
        <h1>Fuel Prices</h1>
        <p>Current effective prices by fuel type and branch.</p>
    </div>
    @if ($canManage)
        <a class="button button-primary" href="{{ route('setup.index') }}#fuel-price-settings">Update fuel prices</a>
    @endif
</div>

<section class="panel table-panel">
    <div class="panel-heading">
        <div><h2>Current prices</h2><p>Prices currently used when recording a sale</p></div>
        <span class="count-pill">{{ $prices->count() }} prices</span>
    </div>
    @if ($prices->isEmpty())
        <div class="table-empty">
            <span>₱</span>
            <strong>No fuel prices are set yet</strong>
            <p>Ask a manager to add the current price for each fuel type and branch.</p>
            @if ($canManage)<a class="button button-primary" href="{{ route('setup.index') }}#fuel-price-settings">Set a fuel price</a>@endif
        </div>
    @else
        <div class="table-scroll">
            <table>
                <thead><tr><th>BRANCH</th><th>FUEL TYPE</th><th>PRICE / LITER</th><th>EFFECTIVE FROM</th></tr></thead>
                <tbody>
                    @foreach ($prices as $price)
                        <tr>
                            <td><strong>{{ $price->branch->name }}</strong></td>
                            <td>{{ $price->fuelProduct->name }}</td>
                            <td><strong>₱{{ number_format((float) $price->price_per_liter, 2) }}</strong></td>
                            <td>{{ $price->effective_from->format('M j, Y · g:i A') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection

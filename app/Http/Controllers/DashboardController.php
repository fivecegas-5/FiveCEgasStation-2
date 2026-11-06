<?php

namespace App\Http\Controllers;

use App\Models\FuelDelivery;
use App\Models\FuelSale;
use App\Models\FuelTank;
use App\Models\InventoryMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        if (! $request->user()->isOwner()) {
            return redirect()->route('sales.index');
        }

        return view('dashboard');
    }

    public function data(Request $request): JsonResponse
    {
        $user = $request->user();
        $dashboard = $this->dashboardData($request);

        return response()->json([
            'user' => [
                'name' => $user->name,
                'branch' => $user->isOwner() && $user->branch_id === null
                    ? 'All branches'
                    : $user->branch?->name,
            ],
            'can_manage' => $user->isOwner(),
            'inventory' => $dashboard['inventoryByProduct']->map(fn (array $inventory): array => [
                'product' => $inventory['product']->name,
                'branch' => $inventory['branch'],
                'stock_liters' => $inventory['stock'],
                'capacity_liters' => $inventory['capacity'],
                'tank_count' => $inventory['tank_count'],
                'stock_percentage' => $inventory['percentage'],
            ])->values(),
            'tanks' => $dashboard['chartTanks'],
            'recent_transactions' => $dashboard['recentTransactions']->map(function (array $transaction): array {
                $movement = $transaction['movement'];

                return [
                    'date' => $movement->occurred_at->format('M j'),
                    'time' => $movement->occurred_at->format('g:i A'),
                    'type' => $transaction['type'],
                    'direction' => $transaction['direction'],
                    'fuel' => $movement->tank->fuelProduct->name,
                    'branch' => $movement->branch->name,
                    'liters' => abs((float) $movement->signed_quantity_liters),
                    'amount' => $transaction['amount'],
                ];
            })->values(),
        ]);
    }

    private function dashboardData(Request $request): array
    {
        $user = $request->user();
        $movements = InventoryMovement::visibleTo($user);
        $tanks = FuelTank::visibleTo($user)->with('fuelProduct', 'branch')->get();

        $stockByTank = $movements
            ->selectRaw('fuel_tank_id, SUM(signed_quantity_liters) as stock_liters')
            ->groupBy('fuel_tank_id')
            ->pluck('stock_liters', 'fuel_tank_id');

        $inventoryByProduct = $tanks
            ->groupBy(fn (FuelTank $tank): string => $tank->branch_id.'-'.$tank->fuel_product_id)
            ->map(function (Collection $productTanks) use ($stockByTank): array {
                $firstTank = $productTanks->first();
                $capacity = (float) $productTanks->sum('capacity_liters');
                $stock = (float) $productTanks->sum(
                    fn (FuelTank $tank): float => (float) ($stockByTank[$tank->id] ?? 0),
                );

                return [
                    'product' => $firstTank->fuelProduct,
                    'branch' => $firstTank->branch->name,
                    'stock' => $stock,
                    'capacity' => $capacity,
                    'tank_count' => $productTanks->count(),
                    'branch_count' => $productTanks->pluck('branch_id')->unique()->count(),
                    'percentage' => $capacity > 0 ? max(0, min(100, $stock / $capacity * 100)) : 0,
                ];
            })
            ->values();

        $chartTanks = $tanks
            ->map(function (FuelTank $tank) use ($stockByTank): array {
                $stock = (float) ($stockByTank[$tank->id] ?? 0);
                $capacity = (float) $tank->capacity_liters;

                return [
                    'label' => $tank->code,
                    'fuel' => $tank->fuelProduct->name,
                    'branch' => $tank->branch->name,
                    'stock' => $stock,
                    'capacity' => $capacity,
                    'stock_percentage' => $capacity > 0 ? max(0, min(100, $stock / $capacity * 100)) : 0,
                ];
            })
            ->values();

        $recentTransactions = InventoryMovement::visibleTo($user)
            ->with(['tank.fuelProduct', 'branch', 'recorder', 'source'])
            ->latest('occurred_at')
            ->limit(8)
            ->get()
            ->map(function (InventoryMovement $movement): array {
                $isStockIn = (float) $movement->signed_quantity_liters > 0;
                $amount = match (true) {
                    $movement->source instanceof FuelSale => (float) $movement->source->total_amount,
                    $movement->source instanceof FuelDelivery => (float) $movement->source->total_cost,
                    default => null,
                };

                return [
                    'movement' => $movement,
                    'type' => match ($movement->movement_type) {
                        'sale' => 'Stock-Out',
                        'delivery' => 'Stock-In',
                        'adjustment' => 'Adjustment',
                        'opening_balance' => 'Opening Stock',
                        default => str($movement->movement_type)->headline()->toString(),
                    },
                    'direction' => $isStockIn ? 'in' : 'out',
                    'amount' => $amount,
                ];
            });

        return [
            'tanks' => $tanks,
            'inventoryByProduct' => $inventoryByProduct,
            'chartTanks' => $chartTanks,
            'recentTransactions' => $recentTransactions,
        ];
    }
}

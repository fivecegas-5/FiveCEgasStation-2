<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\FuelDelivery;
use App\Models\FuelSale;
use App\Models\FuelTank;
use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $movements = InventoryMovement::visibleTo($user);
        $tanks = FuelTank::visibleTo($user)->with('fuelProduct', 'branch')->get();

        $stockByTank = $movements
            ->selectRaw('fuel_tank_id, SUM(signed_quantity_liters) as stock_liters')
            ->groupBy('fuel_tank_id')
            ->pluck('stock_liters', 'fuel_tank_id');

        $inventoryByProduct = $tanks
            ->groupBy('fuel_product_id')
            ->map(function (Collection $productTanks) use ($stockByTank): array {
                $firstTank = $productTanks->first();
                $capacity = (float) $productTanks->sum('capacity_liters');
                $stock = (float) $productTanks->sum(
                    fn (FuelTank $tank): float => (float) ($stockByTank[$tank->id] ?? 0),
                );

                return [
                    'product' => $firstTank->fuelProduct,
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

        return view('dashboard', [
            'tanks' => $tanks,
            'inventoryByProduct' => $inventoryByProduct,
            'chartTanks' => $chartTanks,
            'recentTransactions' => $recentTransactions,
            'canManage' => in_array($user->role, [UserRole::Owner, UserRole::Manager], true),
            'branches' => $user->isOwner() ? Branch::where('is_active', true)->orderBy('name')->get() : collect(),
        ]);
    }
}

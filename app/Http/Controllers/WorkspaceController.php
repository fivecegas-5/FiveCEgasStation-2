<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\FuelDelivery;
use App\Models\FuelNozzle;
use App\Models\FuelPrice;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelSale;
use App\Models\FuelTank;
use App\Models\InventoryMovement;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $branches = Branch::visibleTo($user)->where('is_active', true)->orderBy('name')->get();
        $canSwitchBranch = $user->isOwner() && $user->branch_id === null;
        $activeBranchId = $canSwitchBranch
            ? $request->session()->get('active_branch_id')
            : $user->branch_id;
        $activeBranch = $branches->firstWhere('id', (int) $activeBranchId) ?? $branches->first();
        $activeBranchId = $activeBranch?->id;
        if ($canSwitchBranch && $activeBranchId !== null) {
            $request->session()->put('active_branch_id', $activeBranchId);
        }
        $branchIds = $branches
            ->where('id', $activeBranchId)
            ->pluck('id');
        $pageSize = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'my_sales' => ['nullable', 'boolean'],
        ])['per_page'] ?? 25;

        $tanks = FuelTank::query()
            ->whereIn('branch_id', $branchIds)
            ->with(['branch', 'fuelProduct', 'nozzles'])
            ->orderBy('branch_id')
            ->orderBy('code')
            ->get();
        $stockByTank = InventoryMovement::query()
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('fuel_tank_id, SUM(signed_quantity_liters) as stock_liters')
            ->groupBy('fuel_tank_id')
            ->pluck('stock_liters', 'fuel_tank_id');

        $sales = FuelSale::query()
            ->whereIn('branch_id', $branchIds)
            ->when($request->boolean('my_sales'), fn ($query) => $query->where('recorded_by', $user->id))
            ->with(['branch', 'fuelProduct', 'nozzle.pump', 'recorder'])
            ->latest('sold_at')
            ->paginate($pageSize, ['*'], 'sales_page');
        $deliveries = FuelDelivery::query()
            ->whereIn('branch_id', $branchIds)
            ->with(['branch', 'tank.fuelProduct', 'recorder', 'reviewer'])
            ->latest('received_at')
            ->paginate($pageSize, ['*'], 'deliveries_page');
        $adjustments = StockAdjustment::query()
            ->whereIn('branch_id', $branchIds)
            ->with(['branch', 'tank.fuelProduct', 'requester', 'reviewer'])
            ->latest()
            ->paginate($pageSize, ['*'], 'adjustments_page');

        $prices = FuelPrice::query()
            ->whereIn('branch_id', $branchIds)
            ->where('effective_from', '<=', now())
            ->with(['branch', 'fuelProduct', 'setter'])
            ->orderByDesc('effective_from')
            ->get()
            ->unique(fn (FuelPrice $price): string => $price->branch_id.'-'.$price->fuel_product_id)
            ->sortBy(fn (FuelPrice $price): string => $this->fuelSortKey($price->fuelProduct->name))
            ->values();

        $pumps = FuelPump::query()
            ->whereIn('branch_id', $branchIds)
            ->with(['branch', 'nozzles.tank.fuelProduct'])
            ->orderBy('branch_id')
            ->orderBy('code')
            ->get();
        $products = FuelProduct::query()->orderBy('name')->get();
        $users = User::query()
            ->where('role', UserRole::Staff->value)
            ->whereIn('branch_id', $branchIds)
            ->with('branch')
            ->orderBy('name')
            ->get();

        $todaySales = FuelSale::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'completed')
            ->whereDate('sold_at', today());
        $salesTotal = (clone $todaySales)->sum('total_amount');
        $litersTotal = (clone $todaySales)->sum('liters');
        $stockTotal = $tanks->sum(fn (FuelTank $tank): float => (float) ($stockByTank[$tank->id] ?? 0));
        $pendingDeliveries = FuelDelivery::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'pending')
            ->count();
        $pendingAdjustments = StockAdjustment::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'pending')
            ->count();

        return response()->json([
            'active_branch_id' => $activeBranchId,
            'branches' => $branches->map(fn (Branch $branch): array => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
                'address' => $branch->address,
                'is_active' => $branch->is_active,
            ])->values(),
            'products' => $products->map(fn (FuelProduct $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'unit' => $product->unit,
                'is_active' => $product->is_active,
            ])->values(),
            'inventory' => $tanks->map(fn (FuelTank $tank): array => [
                'id' => $tank->id,
                'branch_id' => $tank->branch_id,
                'branch' => $tank->branch->name,
                'fuel_product_id' => $tank->fuel_product_id,
                'fuel' => $tank->fuelProduct->name,
                'code' => $tank->code,
                'stock_liters' => (float) ($stockByTank[$tank->id] ?? 0),
                'capacity_liters' => (float) $tank->capacity_liters,
                'low_stock_threshold_liters' => $tank->low_stock_threshold_liters === null
                    ? null
                    : (float) $tank->low_stock_threshold_liters,
                'nozzle_count' => $tank->nozzles->count(),
                'is_active' => $tank->is_active,
            ])->values(),
            'sales' => $sales->getCollection()->map(fn (FuelSale $sale): array => [
                'id' => $sale->id,
                'receipt' => $sale->receipt_number,
                'fuel' => $sale->fuelProduct->name,
                'branch' => $sale->branch->name,
                'branch_id' => $sale->branch_id,
                'nozzle' => $sale->nozzle->code,
                'pump' => $sale->nozzle->pump->label ?: $sale->nozzle->pump->code,
                'sold_at' => $sale->sold_at?->toIso8601String(),
                'liters' => (float) $sale->liters,
                'price_per_liter' => (float) $sale->price_per_liter,
                'amount' => (float) $sale->total_amount,
                'status' => $sale->status,
                'payment_method' => $sale->payment_method,
                'recorder' => $sale->recorder->name,
                'is_imported' => $sale->sales_import_batch_id !== null,
                'can_edit' => $user->isOwner() && $sale->sales_import_batch_id === null && $sale->status === 'completed',
                'can_void' => $user->isOwner() && $sale->sales_import_batch_id === null && $sale->status === 'completed',
            ])->values(),
            'deliveries' => $deliveries->getCollection()->map(fn (FuelDelivery $delivery): array => [
                'id' => $delivery->id,
                'branch_id' => $delivery->branch_id,
                'branch' => $delivery->branch->name,
                'tank_id' => $delivery->fuel_tank_id,
                'tank' => $delivery->tank->code,
                'fuel' => $delivery->tank->fuelProduct->name,
                'supplier' => $delivery->supplier_name,
                'reference' => $delivery->delivery_reference,
                'liters' => (float) $delivery->delivered_liters,
                'cost_per_liter' => (float) $delivery->cost_per_liter,
                'total_cost' => (float) $delivery->total_cost,
                'received_at' => $delivery->received_at?->toIso8601String(),
                'status' => $delivery->status,
                'recorder' => $delivery->recorder->name,
                'review_notes' => $delivery->review_notes,
                'can_edit' => $user->isOwner() && $delivery->status === 'pending',
                'can_reverse' => $user->isOwner() && $delivery->status === 'approved',
            ])->values(),
            'adjustments' => $adjustments->getCollection()->map(fn (StockAdjustment $adjustment): array => [
                'id' => $adjustment->id,
                'branch_id' => $adjustment->branch_id,
                'branch' => $adjustment->branch->name,
                'tank_id' => $adjustment->fuel_tank_id,
                'tank' => $adjustment->tank->code,
                'fuel' => $adjustment->tank->fuelProduct->name,
                'quantity_change_liters' => (float) $adjustment->quantity_change_liters,
                'reason' => $adjustment->reason,
                'status' => $adjustment->status,
                'requester' => $adjustment->requester->name,
                'review_notes' => $adjustment->review_notes,
                'can_edit' => $user->isOwner() && $adjustment->status === 'pending',
                'can_reverse' => $user->isOwner() && $adjustment->status === 'approved',
            ])->values(),
            'pagination' => [
                'sales' => $this->paginationData($sales),
                'deliveries' => $this->paginationData($deliveries),
                'adjustments' => $this->paginationData($adjustments),
            ],
            'prices' => $prices->map(fn (FuelPrice $price): array => [
                'id' => $price->id,
                'branch_id' => $price->branch_id,
                'branch' => $price->branch->name,
                'product_id' => $price->fuel_product_id,
                'fuel' => $price->fuelProduct->name,
                'price_per_liter' => (float) $price->price_per_liter,
                'effective_from' => $price->effective_from?->toIso8601String(),
                'setter' => $price->setter->name,
            ])->values(),
            'pumps' => $pumps->map(fn (FuelPump $pump): array => [
                'id' => $pump->id,
                'branch_id' => $pump->branch_id,
                'branch' => $pump->branch->name,
                'code' => $pump->code,
                'label' => $pump->label,
                'is_active' => $pump->is_active,
                'nozzles' => $pump->nozzles->map(fn (FuelNozzle $nozzle): array => [
                    'id' => $nozzle->id,
                    'code' => $nozzle->code,
                    'tank_id' => $nozzle->fuel_tank_id,
                    'tank' => $nozzle->tank->code,
                    'fuel' => $nozzle->tank->fuelProduct->name,
                    'is_active' => $nozzle->is_active,
                ])->values(),
            ])->values(),
            'users' => $users->map(fn (User $staff): array => [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'branch_id' => $staff->branch_id,
                'branch' => $staff->branch?->name,
                'role' => $staff->role->value,
                'is_active' => $staff->is_active,
            ])->values(),
            'summary' => [
                'today_sales' => (float) $salesTotal,
                'today_liters' => (float) $litersTotal,
                'today_transaction_count' => (clone $todaySales)->count(),
                'stock_liters' => (float) $stockTotal,
                'pending_deliveries' => $pendingDeliveries,
                'pending_adjustments' => $pendingAdjustments,
            ],
        ]);
    }

    private function paginationData(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    private function fuelSortKey(string $name): string
    {
        $normalized = strtolower($name);
        $rank = str_contains($normalized, 'unleaded')
            ? 0
            : (str_contains($normalized, 'premium') ? 1 : (str_contains($normalized, 'diesel') ? 2 : 3));

        return sprintf('%d-%s', $rank, $normalized);
    }
}

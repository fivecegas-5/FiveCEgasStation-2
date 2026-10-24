<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FuelNozzle;
use App\Models\FuelPrice;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelTank;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SetupController extends Controller
{
    public function index(Request $request): View
    {
        $branches = Branch::query()->where('is_active', true)->orderBy('name');
        if ($request->user()->role !== UserRole::Owner) {
            $branches->whereKey($request->user()->branch_id);
        }

        $visibleBranchIds = (clone $branches)->pluck('id');

        return view('setup.index', [
            'branches' => $branches->get(),
            'products' => FuelProduct::where('is_active', true)->orderBy('name')->get(),
            'tanks' => FuelTank::with('branch', 'fuelProduct')
                ->whereIn('branch_id', $visibleBranchIds)
                ->orderBy('branch_id')
                ->orderBy('code')
                ->get(),
            'stockByTank' => InventoryMovement::query()
                ->selectRaw('fuel_tank_id, SUM(signed_quantity_liters) as stock_liters')
                ->whereIn('branch_id', $visibleBranchIds)
                ->groupBy('fuel_tank_id')
                ->pluck('stock_liters', 'fuel_tank_id'),
            'pumps' => FuelPump::with('branch', 'nozzles.tank')
                ->whereIn('branch_id', $visibleBranchIds)
                ->orderBy('branch_id')
                ->orderBy('code')
                ->get(),
            'prices' => FuelPrice::with('branch', 'fuelProduct', 'setter')
                ->whereIn('branch_id', $visibleBranchIds)
                ->where('effective_from', '<=', now())
                ->orderByDesc('effective_from')
                ->get()
                ->unique(fn (FuelPrice $price) => $price->branch_id.'-'.$price->fuel_product_id)
                ->values(),
            'canChooseBranch' => $request->user()->role === UserRole::Owner,
        ]);
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:fuel_products,name'],
        ]);
        $product = FuelProduct::create(['name' => $data['name'], 'unit' => 'liter']);
        $this->audit($request, 'fuel-product.created', $product, null, $product->getAttributes());

        return back()->with('success', 'Fuel type added.');
    }

    public function storeTank(Request $request): RedirectResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('fuel_tanks', 'code')->where('branch_id', $branchId)],
            'fuel_product_id' => ['required', 'integer', 'exists:fuel_products,id'],
            'capacity_liters' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'opening_stock_liters' => ['required', 'numeric', 'gte:0', 'lte:capacity_liters'],
        ]);

        DB::transaction(function () use ($request, $data, $branchId): void {
            $tank = FuelTank::create([
                'branch_id' => $branchId,
                'fuel_product_id' => $data['fuel_product_id'],
                'code' => $data['code'],
                'capacity_liters' => $data['capacity_liters'],
            ]);

            $tank->inventoryMovements()->create([
                'branch_id' => $branchId,
                'fuel_tank_id' => $tank->id,
                'movement_type' => 'opening_balance',
                'signed_quantity_liters' => $data['opening_stock_liters'],
                'recorded_by' => $request->user()->id,
                'occurred_at' => now(),
            ]);

            $this->audit($request, 'fuel-tank.created', $tank, null, $tank->getAttributes());
        });

        return back()->with('success', 'Tank and opening stock recorded.');
    }

    public function updateTank(Request $request, FuelTank $tank): RedirectResponse
    {
        $tank = FuelTank::visibleTo($request->user())->findOrFail($tank->id);
        $data = $request->validate([
            'capacity_liters' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'current_stock_liters' => ['required', 'numeric', 'gte:0', 'lte:capacity_liters'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $data, $tank): void {
            $lockedTank = FuelTank::visibleTo($request->user())
                ->whereKey($tank->id)
                ->lockForUpdate()
                ->firstOrFail();
            $currentStock = (float) $lockedTank->movements()->sum('signed_quantity_liters');
            $currentStockMilliLiters = (int) round($currentStock * 1000);
            $newStockMilliLiters = (int) round((float) $data['current_stock_liters'] * 1000);
            $stockChangeMilliLiters = $newStockMilliLiters - $currentStockMilliLiters;

            if ($stockChangeMilliLiters !== 0 && blank($data['reason'] ?? null)) {
                throw ValidationException::withMessages([
                    'reason' => 'A reason is required when correcting the measured stock balance.',
                ]);
            }

            $before = [
                'capacity_liters' => $lockedTank->capacity_liters,
                'current_stock_liters' => number_format($currentStock, 3, '.', ''),
            ];
            $lockedTank->update(['capacity_liters' => $data['capacity_liters']]);

            if ($stockChangeMilliLiters !== 0) {
                $lockedTank->inventoryMovements()->create([
                    'branch_id' => $lockedTank->branch_id,
                    'fuel_tank_id' => $lockedTank->id,
                    'movement_type' => 'stock_reconciliation',
                    'signed_quantity_liters' => $stockChangeMilliLiters / 1000,
                    'recorded_by' => $request->user()->id,
                    'occurred_at' => now(),
                ]);
            }

            $this->audit(
                $request,
                'fuel-tank.updated',
                $lockedTank,
                $before,
                [
                    'capacity_liters' => $lockedTank->capacity_liters,
                    'current_stock_liters' => number_format($newStockMilliLiters / 1000, 3, '.', ''),
                    'stock_change_liters' => number_format($stockChangeMilliLiters / 1000, 3, '.', ''),
                    'reason' => $data['reason'] ?? null,
                ],
            );
        });

        return back()->with('success', 'Tank capacity saved. Any stock correction was recorded in the inventory history.');
    }

    public function storeNozzle(Request $request): RedirectResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'pump_code' => ['required', 'string', 'max:50'],
            'nozzle_code' => ['required', 'string', 'max:50'],
            'fuel_tank_id' => ['required', 'integer'],
        ]);

        $tank = FuelTank::where('branch_id', $branchId)->findOrFail($data['fuel_tank_id']);

        DB::transaction(function () use ($request, $data, $branchId, $tank): void {
            $pump = FuelPump::firstOrCreate(
                ['branch_id' => $branchId, 'code' => $data['pump_code']],
                ['is_active' => true],
            );
            $nozzle = FuelNozzle::create([
                'branch_id' => $branchId,
                'fuel_pump_id' => $pump->id,
                'fuel_tank_id' => $tank->id,
                'code' => $data['nozzle_code'],
            ]);
            $this->audit($request, 'fuel-nozzle.created', $nozzle, null, $nozzle->getAttributes());
        });

        return back()->with('success', 'Pump and nozzle configured.');
    }

    public function storePrice(Request $request): RedirectResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'fuel_product_id' => ['required', 'integer', 'exists:fuel_products,id'],
            'price_per_liter' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'effective_from' => ['required', 'date', 'after_or_equal:now'],
        ]);

        $price = FuelPrice::create([
            'branch_id' => $branchId,
            'fuel_product_id' => $data['fuel_product_id'],
            'price_per_liter' => $data['price_per_liter'],
            'effective_from' => $data['effective_from'],
            'set_by' => $request->user()->id,
        ]);
        $this->audit($request, 'fuel-price.created', $price, null, $price->getAttributes());

        return back()->with('success', 'Branch fuel price saved with its effective date.');
    }

    public function updatePrice(Request $request, FuelPrice $price): RedirectResponse
    {
        $currentPrice = FuelPrice::visibleTo($request->user())
            ->whereKey($price->id)
            ->firstOrFail();
        $data = $request->validate([
            'price_per_liter' => ['required', 'numeric', 'gt:0', 'max:100000'],
        ]);

        DB::transaction(function () use ($request, $data, $currentPrice): void {
            $effectiveFrom = now()->startOfSecond();
            while (FuelPrice::query()
                ->where('branch_id', $currentPrice->branch_id)
                ->where('fuel_product_id', $currentPrice->fuel_product_id)
                ->where('effective_from', $effectiveFrom)
                ->exists()) {
                $effectiveFrom->addSecond();
            }

            $newPrice = FuelPrice::create([
                'branch_id' => $currentPrice->branch_id,
                'fuel_product_id' => $currentPrice->fuel_product_id,
                'price_per_liter' => $data['price_per_liter'],
                'effective_from' => $effectiveFrom,
                'set_by' => $request->user()->id,
            ]);

            $this->audit(
                $request,
                'fuel-price.updated',
                $newPrice,
                ['price_per_liter' => $currentPrice->price_per_liter],
                [
                    'price_per_liter' => $newPrice->price_per_liter,
                    'supersedes_price_id' => $currentPrice->id,
                    'effective_from' => $newPrice->effective_from->toIso8601String(),
                ],
            );
        });

        return back()->with('success', 'Fuel price updated. Previous sales and price history were preserved.');
    }

    private function branchId(Request $request): int
    {
        if ($request->user()->role === UserRole::Owner) {
            return (int) $request->validate([
                'branch_id' => ['required', 'integer', 'exists:branches,id'],
            ])['branch_id'];
        }

        abort_unless($request->user()->branch_id !== null, 403);

        return (int) $request->user()->branch_id;
    }

    private function audit(Request $request, string $action, FuelProduct|FuelTank|FuelNozzle|FuelPrice $subject, ?array $before, ?array $after): void
    {
        $user = $request->user();
        $branchId = $subject->getAttribute('branch_id')
            ?? ($subject instanceof FuelProduct && $user instanceof User ? $user->branch_id : null);

        AuditLog::create([
            'branch_id' => $branchId,
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'occurred_at' => now(),
        ]);
    }
}

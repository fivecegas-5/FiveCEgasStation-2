<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBranchContext;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FuelNozzle;
use App\Models\FuelPrice;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelTank;
use App\Models\InventoryMovement;
use App\Models\Shift;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Services\InventoryLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SetupController extends Controller
{
    use ResolvesBranchContext;

    public function index(Request $request): View
    {
        $allBranches = Branch::visibleTo($request->user())->orderBy('name')->get();
        $branches = $allBranches->where('is_active', true)->values();

        $visibleBranchIds = $allBranches->pluck('id');

        $products = FuelProduct::orderBy('name')->get();
        $tanks = FuelTank::with('branch', 'fuelProduct')
                ->whereIn('branch_id', $visibleBranchIds)
                ->orderBy('branch_id')
                ->orderBy('code')
                ->get();
        $stockByTank = InventoryMovement::query()
                ->selectRaw('fuel_tank_id, SUM(signed_quantity_liters) as stock_liters')
                ->whereIn('branch_id', $visibleBranchIds)
                ->groupBy('fuel_tank_id')
                ->pluck('stock_liters', 'fuel_tank_id');
        $pumps = FuelPump::with('branch', 'nozzles.tank.fuelProduct')
                ->whereIn('branch_id', $visibleBranchIds)
                ->orderBy('branch_id')
                ->orderBy('code')
                ->get();
        $prices = FuelPrice::with('branch', 'fuelProduct', 'setter')
                ->whereIn('branch_id', $visibleBranchIds)
                ->where('effective_from', '<=', now())
                ->orderByDesc('effective_from')
                ->get()
                ->unique(fn (FuelPrice $price) => $price->branch_id.'-'.$price->fuel_product_id)
                ->values();

        return view('setup.index', [
            'branches' => $branches,
            'products' => $products,
            'tanks' => $tanks,
            'stockByTank' => $stockByTank,
            'pumps' => $pumps,
            'setupData' => [
                'branches' => $allBranches->map(fn (Branch $branch) => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                    'address' => $branch->address,
                    'isActive' => $branch->is_active,
                ])->values(),
                'products' => $products->map(fn (FuelProduct $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'isActive' => $product->is_active,
                ])->values(),
                'tanks' => $tanks->map(fn (FuelTank $tank) => [
                    'id' => $tank->id,
                    'branchId' => $tank->branch_id,
                    'branchName' => $tank->branch->name,
                    'code' => $tank->code,
                    'productId' => $tank->fuel_product_id,
                    'productName' => $tank->fuelProduct->name,
                    'capacity' => (float) $tank->capacity_liters,
                    'stock' => (float) ($stockByTank->get($tank->id) ?? 0),
                    'lowStockThreshold' => $tank->low_stock_threshold_liters === null
                        ? null
                        : (float) $tank->low_stock_threshold_liters,
                    'isActive' => $tank->is_active,
                ])->values(),
                'prices' => $prices->map(fn (FuelPrice $price) => [
                    'id' => $price->id,
                    'branchId' => $price->branch_id,
                    'branchName' => $price->branch->name,
                    'productName' => $price->fuelProduct->name,
                    'amount' => (float) $price->price_per_liter,
                ])->values(),
                'pumps' => $pumps->map(fn (FuelPump $pump) => [
                    'id' => $pump->id,
                    'branchId' => $pump->branch_id,
                    'branchName' => $pump->branch->name,
                    'code' => $pump->code,
                    'label' => $pump->label,
                    'isActive' => $pump->is_active,
                    'nozzles' => $pump->nozzles->map(fn (FuelNozzle $nozzle) => [
                        'id' => $nozzle->id,
                        'code' => $nozzle->code,
                        'productName' => $nozzle->tank->fuelProduct->name,
                        'tankId' => $nozzle->fuel_tank_id,
                        'tankCode' => $nozzle->tank->code,
                        'isActive' => $nozzle->is_active,
                    ])->values(),
                ])->values(),
            ],
            'canChooseBranch' => $request->user()->isOwner() && $request->user()->branch_id === null,
        ]);
    }

    public function storeBranch(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isOwner() && $request->user()->branch_id === null, 403);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:branches,code'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $branch = Branch::create($data + ['is_active' => true]);
            $this->audit($request, 'branch.created', $branch, null, $branch->getAttributes());
        });

        return back()->with('success', 'Branch added.');
    }

    public function updateBranch(Request $request, Branch $branch): RedirectResponse
    {
        $branch = Branch::visibleTo($request->user())->whereKey($branch->id)->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
        DB::transaction(function () use ($request, $branch, $data): void {
            $lockedBranch = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $before = ['name' => $lockedBranch->name, 'address' => $lockedBranch->address];
            $lockedBranch->update($data);
            $this->audit($request, 'branch.updated', $lockedBranch, $before, [
                'name' => $lockedBranch->name,
                'address' => $lockedBranch->address,
            ]);
        });

        return back()->with('success', 'Branch details updated.');
    }

    public function updateBranchStatus(Request $request, Branch $branch): RedirectResponse
    {
        $branch = Branch::visibleTo($request->user())->whereKey($branch->id)->firstOrFail();
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $isActive = (bool) $data['is_active'];
        DB::transaction(function () use ($request, $branch, $isActive): void {
            $lockedBranch = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            if (! $isActive) {
                abort_if(
                    Shift::query()->where('branch_id', $lockedBranch->id)->where('status', 'open')->exists(),
                    409,
                    'Close the branch shift before archiving this branch.',
                );
            }
            $before = ['is_active' => $lockedBranch->is_active];
            $lockedBranch->update(['is_active' => $isActive]);
            $this->audit(
                $request,
                $isActive ? 'branch.activated' : 'branch.deactivated',
                $lockedBranch,
                $before,
                ['is_active' => $isActive],
            );
        });

        return back()->with('success', 'Branch status updated.');
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:fuel_products,name'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $product = FuelProduct::create(['name' => $data['name'], 'unit' => 'liter']);
            $this->audit($request, 'fuel-product.created', $product, null, $product->getAttributes());
        });

        return back()->with('success', 'Fuel type added.');
    }

    public function updateProduct(Request $request, FuelProduct $product): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('fuel_products', 'name')->ignore($product->id)],
        ]);
        DB::transaction(function () use ($request, $product, $data): void {
            $lockedProduct = FuelProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $before = ['name' => $lockedProduct->name];
            $lockedProduct->update(['name' => $data['name']]);
            $this->audit($request, 'fuel-product.updated', $lockedProduct, $before, ['name' => $lockedProduct->name]);
        });

        return back()->with('success', 'Fuel type updated. Historical movements remain unchanged.');
    }

    public function updateProductStatus(Request $request, FuelProduct $product): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $isActive = (bool) $data['is_active'];

        DB::transaction(function () use ($request, $product, $isActive): void {
            $lockedProduct = FuelProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if (! $isActive && $lockedProduct->fuelTanks()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'product' => 'A fuel type assigned to an active tank cannot be deactivated.',
                ]);
            }

            $before = ['is_active' => $lockedProduct->is_active];
            $lockedProduct->update(['is_active' => $isActive]);
            $this->audit(
                $request,
                $isActive ? 'fuel-product.activated' : 'fuel-product.deactivated',
                $lockedProduct,
                $before,
                ['is_active' => $isActive],
            );
        });

        return back()->with('success', 'Fuel type status updated. Historical transactions were retained.');
    }

    public function updateTankStatus(Request $request, FuelTank $tank): RedirectResponse
    {
        $tank = FuelTank::visibleTo($request->user())->whereKey($tank->id)->firstOrFail();
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $isActive = (bool) $data['is_active'];

        DB::transaction(function () use ($request, $tank, $isActive): void {
            $this->assertNoOpenShift((int) $tank->branch_id);
            $lockedTank = FuelTank::query()->whereKey($tank->id)->lockForUpdate()->firstOrFail();

            if (! $isActive) {
                abort_if(
                    $lockedTank->nozzles()->where('is_active', true)->exists(),
                    422,
                    'Deactivate connected nozzles before deactivating this tank.',
                );
                abort_if(
                    $lockedTank->deliveries()->where('status', 'pending')->exists()
                        || StockAdjustment::query()->where('fuel_tank_id', $lockedTank->id)->where('status', 'pending')->exists(),
                    422,
                    'Resolve pending deliveries and stock adjustments before deactivating this tank.',
                );
            } else {
                abort_unless($lockedTank->fuelProduct->is_active, 422, 'Activate this fuel product before reactivating its tank.');
            }

            $before = ['is_active' => $lockedTank->is_active];
            $lockedTank->update(['is_active' => $isActive]);
            $this->audit($request, $isActive ? 'fuel-tank.activated' : 'fuel-tank.deactivated', $lockedTank, $before, ['is_active' => $isActive]);
        });

        return back()->with('success', 'Tank status updated.');
    }

    public function updatePumpStatus(Request $request, FuelPump $pump): RedirectResponse
    {
        $pump = FuelPump::visibleTo($request->user())->whereKey($pump->id)->firstOrFail();
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $isActive = (bool) $data['is_active'];

        DB::transaction(function () use ($request, $pump, $isActive): void {
            $this->assertNoOpenShift((int) $pump->branch_id);
            $lockedPump = FuelPump::query()->whereKey($pump->id)->lockForUpdate()->firstOrFail();
            abort_if(
                ! $isActive && $lockedPump->nozzles()->where('is_active', true)->exists(),
                422,
                'Deactivate connected nozzles before deactivating this pump.',
            );
            $before = ['is_active' => $lockedPump->is_active];
            $lockedPump->update(['is_active' => $isActive]);
            $this->audit($request, $isActive ? 'fuel-pump.activated' : 'fuel-pump.deactivated', $lockedPump, $before, ['is_active' => $isActive]);
        });

        return back()->with('success', 'Pump status updated.');
    }

    public function updateNozzleStatus(Request $request, FuelNozzle $nozzle): RedirectResponse
    {
        $nozzle = FuelNozzle::visibleTo($request->user())->whereKey($nozzle->id)->firstOrFail();
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $isActive = (bool) $data['is_active'];

        DB::transaction(function () use ($request, $nozzle, $isActive): void {
            $this->assertNoOpenShift((int) $nozzle->branch_id);
            $lockedNozzle = FuelNozzle::query()->whereKey($nozzle->id)->lockForUpdate()->firstOrFail();
            if ($isActive) {
                abort_unless(
                    $lockedNozzle->pump->is_active && $lockedNozzle->tank->is_active,
                    422,
                    'Activate the connected pump and tank before reactivating this nozzle.',
                );
            }

            $before = ['is_active' => $lockedNozzle->is_active];
            $lockedNozzle->update(['is_active' => $isActive]);
            $this->audit($request, $isActive ? 'fuel-nozzle.activated' : 'fuel-nozzle.deactivated', $lockedNozzle, $before, ['is_active' => $isActive]);
        });

        return back()->with('success', 'Nozzle status updated.');
    }

    public function storeTank(Request $request): RedirectResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('fuel_tanks', 'code')->where('branch_id', $branchId)],
            'fuel_product_id' => ['required', 'integer', Rule::exists('fuel_products', 'id')->where('is_active', true)],
            'capacity_liters' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'opening_stock_liters' => ['required', 'numeric', 'gte:0', 'lte:capacity_liters'],
            'opening_cost_per_liter' => ['nullable', 'numeric', 'gte:0', 'max:100000'],
            'low_stock_threshold_liters' => ['nullable', 'numeric', 'gte:0', 'lte:capacity_liters'],
        ]);

        DB::transaction(function () use ($request, $data, $branchId): void {
            $this->assertBranchActive($branchId);
            $product = FuelProduct::query()
                ->whereKey($data['fuel_product_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();
            $tank = FuelTank::create([
                'branch_id' => $branchId,
                'fuel_product_id' => $product->id,
                'code' => $data['code'],
                'capacity_liters' => $data['capacity_liters'],
                'low_stock_threshold_liters' => $data['low_stock_threshold_liters'] ?? null,
            ]);

            app(InventoryLedger::class)->record(
                $tank,
                'opening_balance',
                (float) $data['opening_stock_liters'],
                $request->user(),
                $tank,
                now(),
                isset($data['opening_cost_per_liter']) ? (float) $data['opening_cost_per_liter'] : null,
            );

            $this->audit($request, 'fuel-tank.created', $tank, null, $tank->getAttributes());
        });

        return back()->with('success', 'Tank and opening stock recorded.');
    }

    public function updateTank(Request $request, FuelTank $tank): RedirectResponse
    {
        $tank = FuelTank::visibleTo($request->user())->findOrFail($tank->id);
        $data = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('fuel_tanks', 'code')->where('branch_id', $tank->branch_id)->ignore($tank->id)],
            'capacity_liters' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'current_stock_liters' => ['required', 'numeric', 'gte:0', 'lte:capacity_liters'],
            'low_stock_threshold_liters' => ['sometimes', 'nullable', 'numeric', 'gte:0', 'lte:capacity_liters'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $data, $tank): void {
            $this->assertNoOpenShift((int) $tank->branch_id);
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
                'code' => $lockedTank->code,
                'capacity_liters' => $lockedTank->capacity_liters,
                'low_stock_threshold_liters' => $lockedTank->low_stock_threshold_liters,
                'current_stock_liters' => number_format($currentStock, 3, '.', ''),
            ];
            $lockedTank->update(collect($data)->only(['code', 'capacity_liters', 'low_stock_threshold_liters'])->all());

            if ($stockChangeMilliLiters !== 0) {
                app(InventoryLedger::class)->record(
                    $lockedTank,
                    'stock_reconciliation',
                    $stockChangeMilliLiters / 1000,
                    $request->user(),
                    $lockedTank,
                    now(),
                );
            }

            $this->audit(
                $request,
                'fuel-tank.updated',
                $lockedTank,
                $before,
                [
                    'code' => $lockedTank->code,
                    'capacity_liters' => $lockedTank->capacity_liters,
                    'low_stock_threshold_liters' => $lockedTank->low_stock_threshold_liters,
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
            'pump_label' => ['nullable', 'string', 'max:100'],
            'nozzle_code' => ['required', 'string', 'max:50'],
            'fuel_tank_id' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($request, $data, $branchId): void {
            $this->assertBranchActive($branchId);
            $tank = FuelTank::where('branch_id', $branchId)
                ->where('is_active', true)
                ->whereKey($data['fuel_tank_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $pump = FuelPump::firstOrCreate(
                ['branch_id' => $branchId, 'code' => $data['pump_code']],
                ['label' => $data['pump_label'] ?? null, 'is_active' => true],
            );
            if ($pump->label === null && filled($data['pump_label'] ?? null)) {
                $pump->update(['label' => $data['pump_label']]);
            }
            abort_unless($pump->is_active, 422, 'Reactivate this pump before adding a nozzle.');
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

    public function storePump(Request $request): RedirectResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('fuel_pumps', 'code')->where('branch_id', $branchId)],
            'label' => ['required', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($request, $branchId, $data): void {
            $this->assertBranchActive($branchId);
            $pump = FuelPump::create([
                'branch_id' => $branchId,
                'code' => $data['code'],
                'label' => $data['label'],
                'is_active' => true,
            ]);
            $this->audit($request, 'fuel-pump.created', $pump, null, $pump->getAttributes());
        });

        return back()->with('success', 'Pump added.');
    }

    public function updatePump(Request $request, FuelPump $pump): RedirectResponse
    {
        $pump = FuelPump::visibleTo($request->user())->whereKey($pump->id)->firstOrFail();
        $data = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('fuel_pumps', 'code')->where('branch_id', $pump->branch_id)->ignore($pump->id)],
            'label' => ['sometimes', 'required', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($request, $pump, $data): void {
            $this->assertNoOpenShift((int) $pump->branch_id);
            $lockedPump = FuelPump::query()->whereKey($pump->id)->lockForUpdate()->firstOrFail();
            abort_if(
                $lockedPump->nozzles()->whereHas('sales')->exists()
                    && isset($data['code'])
                    && $data['code'] !== $lockedPump->code,
                422,
                'A pump with recorded sales cannot change its internal code. Its physical label can still be edited.',
            );
            $before = ['code' => $lockedPump->code, 'label' => $lockedPump->label];
            $lockedPump->update(collect($data)->only(['code', 'label'])->all());
            $this->audit($request, 'fuel-pump.updated', $lockedPump, $before, [
                'code' => $lockedPump->code,
                'label' => $lockedPump->label,
            ]);
        });

        return back()->with('success', 'Pump details updated.');
    }

    public function updateNozzle(Request $request, FuelNozzle $nozzle): RedirectResponse
    {
        $nozzle = FuelNozzle::visibleTo($request->user())->whereKey($nozzle->id)->firstOrFail();
        $targetPumpId = (int) $request->input('fuel_pump_id', $nozzle->fuel_pump_id);
        $data = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('fuel_nozzles', 'code')->where('fuel_pump_id', $targetPumpId)->ignore($nozzle->id)],
            'fuel_pump_id' => ['sometimes', 'required', 'integer'],
            'fuel_tank_id' => ['sometimes', 'required', 'integer'],
        ]);

        DB::transaction(function () use ($request, $nozzle, $data): void {
            $this->assertNoOpenShift((int) $nozzle->branch_id);
            $lockedNozzle = FuelNozzle::query()->whereKey($nozzle->id)->lockForUpdate()->firstOrFail();
            $pumpId = (int) ($data['fuel_pump_id'] ?? $lockedNozzle->fuel_pump_id);
            $tankId = (int) ($data['fuel_tank_id'] ?? $lockedNozzle->fuel_tank_id);
            abort_if(
                $lockedNozzle->sales()->exists()
                    && ($pumpId !== (int) $lockedNozzle->fuel_pump_id
                        || $tankId !== (int) $lockedNozzle->fuel_tank_id
                        || (($data['code'] ?? $lockedNozzle->code) !== $lockedNozzle->code)),
                422,
                'A nozzle with recorded sales cannot change its code or mapping. Create a replacement nozzle to preserve sales history.',
            );
            $pump = FuelPump::query()
                ->where('branch_id', $lockedNozzle->branch_id)
                ->where('is_active', true)
                ->whereKey($pumpId)
                ->firstOrFail();
            $tank = FuelTank::query()
                ->where('branch_id', $lockedNozzle->branch_id)
                ->where('is_active', true)
                ->whereKey($tankId)
                ->firstOrFail();

            $before = [
                'code' => $lockedNozzle->code,
                'fuel_pump_id' => $lockedNozzle->fuel_pump_id,
                'fuel_tank_id' => $lockedNozzle->fuel_tank_id,
            ];
            $lockedNozzle->update([
                'code' => $data['code'] ?? $lockedNozzle->code,
                'fuel_pump_id' => $pump->id,
                'fuel_tank_id' => $tank->id,
            ]);
            $this->audit($request, 'fuel-nozzle.updated', $lockedNozzle, $before, [
                'code' => $lockedNozzle->code,
                'fuel_pump_id' => $lockedNozzle->fuel_pump_id,
                'fuel_tank_id' => $lockedNozzle->fuel_tank_id,
            ]);
        });

        return back()->with('success', 'Nozzle details updated.');
    }

    public function storePrice(Request $request): RedirectResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'fuel_product_id' => ['required', 'integer', Rule::exists('fuel_products', 'id')->where('is_active', true)],
            'price_per_liter' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'effective_from' => ['required', 'date', 'after_or_equal:now'],
        ]);

        DB::transaction(function () use ($request, $data, $branchId): void {
            $this->assertBranchActive($branchId);
            $price = FuelPrice::create([
                'branch_id' => $branchId,
                'fuel_product_id' => $data['fuel_product_id'],
                'price_per_liter' => $data['price_per_liter'],
                'effective_from' => $data['effective_from'],
                'set_by' => $request->user()->id,
            ]);
            $this->audit($request, 'fuel-price.created', $price, null, $price->getAttributes());
        });

        return back()->with('success', 'Branch fuel price saved with its effective date.');
    }

    public function updatePrice(Request $request, FuelPrice $price): RedirectResponse
    {
        $currentPrice = FuelPrice::visibleTo($request->user())
            ->whereKey($price->id)
            ->firstOrFail();
        $data = $request->validate([
            'price_per_liter' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $data, $currentPrice): void {
            $this->assertBranchActive((int) $currentPrice->branch_id);
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
                    'reason' => $data['reason'] ?? null,
                ],
            );
        });

        return back()->with('success', 'Fuel price updated. Previous sales and price history were preserved.');
    }

    private function assertBranchActive(int $branchId): void
    {
        abort_unless(
            Branch::query()->whereKey($branchId)->where('is_active', true)->lockForUpdate()->exists(),
            422,
            'This branch is inactive.',
        );
    }

    private function assertNoOpenShift(int $branchId): void
    {
        Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
        abort_if(
            Shift::query()->where('branch_id', $branchId)->where('status', 'open')->exists(),
            409,
            'Station equipment cannot change while the branch has an open shift.',
        );
    }

    private function audit(Request $request, string $action, Branch|FuelProduct|FuelTank|FuelPump|FuelNozzle|FuelPrice $subject, ?array $before, ?array $after): void
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

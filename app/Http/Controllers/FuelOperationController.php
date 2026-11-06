<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBranchContext;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FuelDelivery;
use App\Models\FuelNozzle;
use App\Models\FuelPrice;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelSale;
use App\Models\FuelTank;
use App\Models\Shift;
use App\Models\StockAdjustment;
use App\Services\InventoryLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class FuelOperationController extends Controller
{
    use ResolvesBranchContext;

    public function prices(Request $request): View
    {
        $this->branchId($request);
        $prices = FuelPrice::visibleTo($request->user())
            ->with('branch', 'fuelProduct')
            ->where('effective_from', '<=', now())
            ->orderBy('branch_id')
            ->orderBy('fuel_product_id')
            ->orderByDesc('effective_from')
            ->get()
            ->unique(fn (FuelPrice $price): string => $price->branch_id.'-'.$price->fuel_product_id)
            ->values();

        return view('operations.prices', [
            'prices' => $prices,
            'canManage' => $request->user()->isOwner(),
        ]);
    }

    public function sales(Request $request): View
    {
        $user = $request->user();
        $branchId = $this->branchId($request);
        $nozzles = FuelNozzle::visibleTo($user)
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->with('pump', 'tank.fuelProduct')
            ->get();
        $prices = FuelPrice::query()
            ->where('branch_id', $branchId)
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->get()
            ->unique('fuel_product_id')
            ->keyBy('fuel_product_id');
        $products = FuelProduct::query()->where('is_active', true)->orderBy('name')->get();
        $fuelTypes = [
            ['label' => 'Unleaded', 'match' => 'unleaded'],
            ['label' => 'Premium', 'match' => 'premium'],
            ['label' => 'Diesel', 'match' => 'diesel'],
        ];
        $fuels = collect($fuelTypes)->map(function (array $fuelType) use ($nozzles, $prices, $products): array {
            $product = $products->first(
                fn (FuelProduct $candidate): bool => str_contains(strtolower($candidate->name), $fuelType['match']),
            );
            $fuelNozzles = $product
                ? $nozzles->filter(fn (FuelNozzle $nozzle): bool => $nozzle->tank->fuel_product_id === $product->id)
                : collect();

            return [
                'id' => $product?->id,
                'name' => $product?->name ?? $fuelType['label'],
                'label' => $fuelType['label'],
                'price_per_liter' => $product && isset($prices[$product->id])
                    ? (float) $prices[$product->id]->price_per_liter
                    : null,
                'nozzles' => $fuelNozzles->map(fn (FuelNozzle $nozzle): array => [
                    'id' => $nozzle->id,
                    'pump' => $nozzle->pump->code,
                    'code' => $nozzle->code,
                ])->values(),
            ];
        });

        return view('operations.sales', [
            'branchId' => $branchId,
            'fuels' => $fuels,
            'sales' => FuelSale::visibleTo($user)
                ->where('branch_id', $branchId)
                ->with('fuelProduct', 'nozzle.pump', 'recorder')
                ->latest('sold_at')
                ->limit(30)
                ->get(),
        ]);
    }

    public function storeSale(Request $request): RedirectResponse|JsonResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'fuel_nozzle_id' => ['required', 'integer', 'exists:fuel_nozzles,id'],
            'liters' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'receipt_number' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', Rule::in(['cash', 'card', 'ewallet', 'other'])],
            'sold_at' => ['required', 'date', 'before_or_equal:now'],
        ]);

        $sale = DB::transaction(function () use ($request, $data, $branchId): FuelSale {
            $branch = Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
            abort_unless($branch->is_active, 422, 'This branch is inactive.');
            $nozzle = FuelNozzle::query()
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->whereKey($data['fuel_nozzle_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $pump = FuelPump::query()->whereKey($nozzle->fuel_pump_id)->firstOrFail();
            abort_unless($pump->branch_id === $branchId && $pump->is_active, 422, 'The nozzle pump is not active in this branch.');

            $openShift = Shift::query()
                ->where('branch_id', $branchId)
                ->where('status', 'open')
                ->latest('opened_at')
                ->lockForUpdate()
                ->first();
            if ($openShift && now()->parse($data['sold_at'])->lt($openShift->opened_at)) {
                throw ValidationException::withMessages([
                    'sold_at' => 'A sale cannot be recorded before the open shift started.',
                ]);
            }
            if (filled($data['receipt_number'] ?? null) && FuelSale::query()
                ->where('branch_id', $branchId)
                ->where('receipt_number', $data['receipt_number'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'receipt_number' => 'This receipt number is already recorded for the branch.',
                ]);
            }
            $tank = app(InventoryLedger::class)->lockTank($branchId, $nozzle->fuel_tank_id);
            $price = FuelPrice::query()
                ->where('branch_id', $branchId)
                ->where('fuel_product_id', $tank->fuel_product_id)
                ->where('effective_from', '<=', $data['sold_at'])
                ->orderByDesc('effective_from')
                ->first();

            if (! $price) {
                throw ValidationException::withMessages([
                    'fuel_nozzle_id' => 'Set an effective fuel price for this product before recording sales.',
                ]);
            }

            $ledger = app(InventoryLedger::class);
            $ledger->assertBalanceWithinCapacity($tank, -((float) $data['liters']));
            $unitCost = $ledger->weightedAverageCost($tank);
            abort_unless($tank->fuelProduct->is_active, 422, 'This fuel product is inactive.');
            $sale = FuelSale::create([
                'branch_id' => $branchId,
                'fuel_nozzle_id' => $nozzle->id,
                'fuel_product_id' => $tank->fuel_product_id,
                'shift_id' => $openShift?->id,
                'fuel_price_id' => $price->id,
                'receipt_number' => $data['receipt_number'] ?: null,
                'liters' => $data['liters'],
                'price_per_liter' => $price->price_per_liter,
                'total_amount' => round((float) $data['liters'] * (float) $price->price_per_liter, 2),
                'payment_method' => $data['payment_method'] ?? null,
                'sold_at' => $data['sold_at'],
                'recorded_by' => $request->user()->id,
            ]);

            $ledger->record(
                $tank,
                'sale',
                -((float) $data['liters']),
                $request->user(),
                $sale,
                $data['sold_at'],
                $unitCost,
            );

            $this->audit($request, 'fuel-sale.recorded', $sale, null, $sale->getAttributes());

            return $sale;
        });

        $message = 'Sale recorded: '.number_format((float) $sale->total_amount, 2).' PHP.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'sale' => [
                    'id' => $sale->id,
                    'fuel' => $sale->fuelProduct->name,
                    'liters' => (float) $sale->liters,
                    'total_amount' => (float) $sale->total_amount,
                    'receipt_number' => $sale->receipt_number,
                ],
            ], 201);
        }

        return redirect()->route('sales.index')->with('success', $message);
    }

    public function deliveries(Request $request): View
    {
        $user = $request->user();
        $branchId = $this->branchId($request);

        return view('operations.deliveries', [
            'branchId' => $branchId,
            'tanks' => FuelTank::visibleTo($user)->where('branch_id', $branchId)->with('fuelProduct')->get(),
            'deliveries' => FuelDelivery::visibleTo($user)
                ->where('branch_id', $branchId)
                ->with('tank.fuelProduct', 'recorder', 'reviewer')
                ->latest('received_at')
                ->limit(30)
                ->get(),
            'canApprove' => $user->isOwner(),
        ]);
    }

    public function storeDelivery(Request $request): RedirectResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'fuel_tank_id' => ['required', 'integer'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'delivery_reference' => ['nullable', 'string', 'max:100'],
            'delivered_liters' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'cost_per_liter' => ['required', 'numeric', 'gte:0', 'max:100000'],
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'invoice' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $invoice = $request->file('invoice');
        $storedPath = null;

        try {
            $delivery = DB::transaction(function () use ($request, $data, $branchId, $invoice, &$storedPath): FuelDelivery {
                $branch = Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
                abort_unless($branch->is_active, 422, 'This branch is inactive.');
                $tank = app(InventoryLedger::class)->lockTank($branchId, (int) $data['fuel_tank_id']);
                $delivery = FuelDelivery::create([
                    'branch_id' => $branchId,
                    'fuel_tank_id' => $tank->id,
                    'supplier_name' => $data['supplier_name'] ?? null,
                    'delivery_reference' => $data['delivery_reference'] ?? null,
                    'delivered_liters' => $data['delivered_liters'],
                    'cost_per_liter' => $data['cost_per_liter'],
                    'total_cost' => round((float) $data['delivered_liters'] * (float) $data['cost_per_liter'], 2),
                    'received_at' => $data['received_at'],
                    'recorded_by' => $request->user()->id,
                    'status' => 'pending',
                ]);

                if ($invoice) {
                    $storedPath = $invoice->store('fuel-delivery-invoices', 'local');
                    if ($storedPath === false) {
                        throw new \RuntimeException('The delivery invoice could not be stored.');
                    }
                    $delivery->update([
                        'invoice_path' => $storedPath,
                        'invoice_original_name' => $invoice->getClientOriginalName(),
                        'invoice_mime_type' => $invoice->getMimeType(),
                        'invoice_size_bytes' => $invoice->getSize(),
                    ]);
                }

                $this->audit($request, 'fuel-delivery.recorded', $delivery, null, $delivery->getAttributes());

                return $delivery;
            });
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }

        return redirect()->route('deliveries.index')->with('success', 'Delivery recorded and is awaiting branch-owner approval.');
    }

    public function updateDelivery(Request $request, FuelDelivery $delivery): RedirectResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $delivery->branch_id), 404);
        $data = $request->validate([
            'fuel_tank_id' => ['required', 'integer'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'delivery_reference' => ['nullable', 'string', 'max:100'],
            'delivered_liters' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'cost_per_liter' => ['required', 'numeric', 'gte:0', 'max:100000'],
            'received_at' => ['required', 'date', 'before_or_equal:now'],
        ]);

        DB::transaction(function () use ($request, $delivery, $data): void {
            $lockedDelivery = FuelDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedDelivery->status === 'pending', 409, 'Only pending deliveries can be edited. Posted deliveries must be reversed.');
            $tank = app(InventoryLedger::class)->lockTank(
                (int) $lockedDelivery->branch_id,
                (int) $data['fuel_tank_id'],
            );
            $before = $lockedDelivery->getAttributes();
            $lockedDelivery->update([
                'fuel_tank_id' => $tank->id,
                'supplier_name' => $data['supplier_name'] ?? null,
                'delivery_reference' => $data['delivery_reference'] ?? null,
                'delivered_liters' => $data['delivered_liters'],
                'cost_per_liter' => $data['cost_per_liter'],
                'total_cost' => round((float) $data['delivered_liters'] * (float) $data['cost_per_liter'], 2),
                'received_at' => $data['received_at'],
            ]);
            $this->audit($request, 'fuel-delivery.updated', $lockedDelivery, $before, $lockedDelivery->getAttributes());
        });

        return redirect()->route('deliveries.index')->with('success', 'Pending delivery updated and audited.');
    }

    public function approveDelivery(Request $request, FuelDelivery $delivery): RedirectResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $delivery->branch_id), 404);

        DB::transaction(function () use ($request, $delivery): void {
            $lockedDelivery = FuelDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedDelivery->status === 'pending', 409, 'This delivery has already been reviewed.');
            $tank = app(InventoryLedger::class)->lockTank(
                (int) $lockedDelivery->branch_id,
                (int) $lockedDelivery->fuel_tank_id,
            );
            $before = $lockedDelivery->getAttributes();
            $lockedDelivery->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            app(InventoryLedger::class)->record(
                $tank,
                'delivery',
                (float) $lockedDelivery->delivered_liters,
                $request->user(),
                $lockedDelivery,
                $lockedDelivery->received_at,
                (float) $lockedDelivery->cost_per_liter,
                'delivered_liters',
            );

            $this->audit($request, 'fuel-delivery.approved', $lockedDelivery, $before, $lockedDelivery->getAttributes());
        });

        return redirect()->route('deliveries.index')->with('success', 'Delivery approved and added to stock.');
    }

    public function downloadDeliveryInvoice(Request $request, FuelDelivery $delivery)
    {
        abort_unless($request->user()->canAccessBranch((int) $delivery->branch_id), 404);
        abort_unless($delivery->invoice_path !== null, 404, 'No invoice is attached to this delivery.');
        abort_unless(Storage::disk('local')->exists($delivery->invoice_path), 404, 'The stored invoice is unavailable.');

        return Storage::disk('local')->download(
            $delivery->invoice_path,
            basename($delivery->invoice_original_name ?? 'delivery-invoice'),
        );
    }

    public function rejectDelivery(Request $request, FuelDelivery $delivery): RedirectResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $delivery->branch_id), 404);
        abort_unless(
            $request->user()->isOwner() || $delivery->recorded_by !== $request->user()->id,
            403,
            'Only a branch owner can review this delivery.',
        );
        $data = $request->validate(['review_notes' => ['required', 'string', 'max:2000']]);

        DB::transaction(function () use ($request, $delivery, $data): void {
            $lockedDelivery = FuelDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedDelivery->status === 'pending', 409, 'This delivery has already been reviewed.');
            $before = $lockedDelivery->getAttributes();
            $lockedDelivery->update([
                'status' => 'rejected',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $data['review_notes'],
            ]);
            $this->audit($request, 'fuel-delivery.rejected', $lockedDelivery, $before, $lockedDelivery->getAttributes());
        });

        return redirect()->route('deliveries.index')->with('success', 'Delivery rejected; inventory was not changed.');
    }

    public function voidDelivery(Request $request, FuelDelivery $delivery): RedirectResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $delivery->branch_id), 404);
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:2000']]);

        DB::transaction(function () use ($request, $delivery, $data): void {
            $lockedDelivery = FuelDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedDelivery->status === 'approved', 409, 'Only approved deliveries can be reversed.');
            $movement = $lockedDelivery->inventoryMovements()->where('movement_type', 'delivery')->oldest('id')->firstOrFail();
            $tank = FuelTank::query()
                ->where('branch_id', $lockedDelivery->branch_id)
                ->whereKey($movement->fuel_tank_id)
                ->lockForUpdate()
                ->firstOrFail();
            $before = $lockedDelivery->getAttributes();

            app(InventoryLedger::class)->record(
                $tank,
                'delivery_void',
                -((float) $movement->signed_quantity_liters),
                $request->user(),
                $lockedDelivery,
                now(),
                $movement->unit_cost_per_liter === null ? null : (float) $movement->unit_cost_per_liter,
                'void_reason',
            );
            $lockedDelivery->update([
                'status' => 'voided',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $data['void_reason'],
            ]);
            $this->audit($request, 'fuel-delivery.voided', $lockedDelivery, $before, $lockedDelivery->getAttributes());
        });

        return redirect()->route('deliveries.index')->with('success', 'Delivery reversed. The offsetting inventory movement was recorded.');
    }

    public function adjustments(Request $request): View
    {
        $user = $request->user();
        $branchId = $this->branchId($request);

        return view('operations.adjustments', [
            'branchId' => $branchId,
            'tanks' => FuelTank::visibleTo($user)->where('branch_id', $branchId)->with('fuelProduct')->get(),
            'adjustments' => StockAdjustment::visibleTo($user)
                ->where('branch_id', $branchId)
                ->with('tank.fuelProduct', 'requester', 'reviewer')
                ->latest()
                ->limit(30)
                ->get(),
            'canApprove' => $user->isOwner(),
        ]);
    }

    public function storeAdjustment(Request $request): RedirectResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'fuel_tank_id' => ['required', 'integer'],
            'quantity_change_liters' => ['required', 'numeric', 'not_in:0', 'between:-100000,100000'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $adjustment = DB::transaction(function () use ($request, $data, $branchId): StockAdjustment {
            $branch = Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
            abort_unless($branch->is_active, 422, 'This branch is inactive.');
            $tank = app(InventoryLedger::class)->lockTank($branchId, (int) $data['fuel_tank_id']);
            $adjustment = StockAdjustment::create([
                'branch_id' => $branchId,
                'fuel_tank_id' => $tank->id,
                'quantity_change_liters' => $data['quantity_change_liters'],
                'reason' => $data['reason'],
                'status' => 'pending',
                'requested_by' => $request->user()->id,
            ]);

            $this->audit($request, 'stock-adjustment.requested', $adjustment, null, $adjustment->getAttributes());

            return $adjustment;
        });

        return redirect()->route('adjustments.index')->with('success', 'Adjustment request sent for branch-owner review.');
    }

    public function updateAdjustment(Request $request, StockAdjustment $adjustment): RedirectResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $adjustment->branch_id), 404);
        $data = $request->validate([
            'fuel_tank_id' => ['required', 'integer'],
            'quantity_change_liters' => ['required', 'numeric', 'not_in:0', 'between:-100000,100000'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $adjustment, $data): void {
            $lockedAdjustment = StockAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedAdjustment->status === 'pending', 409, 'Only pending adjustments can be edited.');
            $tank = app(InventoryLedger::class)->lockTank(
                (int) $lockedAdjustment->branch_id,
                (int) $data['fuel_tank_id'],
            );
            $before = $lockedAdjustment->getAttributes();
            $lockedAdjustment->update([
                'fuel_tank_id' => $tank->id,
                'quantity_change_liters' => $data['quantity_change_liters'],
                'reason' => $data['reason'],
            ]);
            $this->audit($request, 'stock-adjustment.updated', $lockedAdjustment, $before, $lockedAdjustment->getAttributes());
        });

        return redirect()->route('adjustments.index')->with('success', 'Pending adjustment updated and audited.');
    }

    public function approveAdjustment(Request $request, StockAdjustment $adjustment): RedirectResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $adjustment->branch_id), 404);
        abort_unless(
            $request->user()->isOwner() || $adjustment->requested_by !== $request->user()->id,
            403,
            'Only a branch owner can approve this adjustment.',
        );

        DB::transaction(function () use ($request, $adjustment): void {
            $lockedAdjustment = StockAdjustment::whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedAdjustment->status === 'pending', 409, 'This adjustment has already been reviewed.');
            $tank = app(InventoryLedger::class)->lockTank(
                (int) $lockedAdjustment->branch_id,
                (int) $lockedAdjustment->fuel_tank_id,
            );
            $before = $lockedAdjustment->getAttributes();
            $lockedAdjustment->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            app(InventoryLedger::class)->record(
                $tank,
                'adjustment',
                (float) $lockedAdjustment->quantity_change_liters,
                $request->user(),
                $lockedAdjustment,
                now(),
                null,
                'quantity_change_liters',
            );

            $this->audit($request, 'stock-adjustment.approved', $lockedAdjustment, $before, $lockedAdjustment->getAttributes());
        });

        return redirect()->route('adjustments.index')->with('success', 'Adjustment approved and recorded in the stock ledger.');
    }

    public function rejectAdjustment(Request $request, StockAdjustment $adjustment): RedirectResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $adjustment->branch_id), 404);
        abort_unless(
            $request->user()->isOwner() || $adjustment->requested_by !== $request->user()->id,
            403,
            'Only a branch owner can review this adjustment.',
        );
        $data = $request->validate(['review_notes' => ['required', 'string', 'max:2000']]);

        DB::transaction(function () use ($request, $adjustment, $data): void {
            $lockedAdjustment = StockAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedAdjustment->status === 'pending', 409, 'This adjustment has already been reviewed.');
            $before = $lockedAdjustment->getAttributes();
            $lockedAdjustment->update([
                'status' => 'rejected',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $data['review_notes'],
            ]);
            $this->audit($request, 'stock-adjustment.rejected', $lockedAdjustment, $before, $lockedAdjustment->getAttributes());
        });

        return redirect()->route('adjustments.index')->with('success', 'Adjustment rejected; inventory was not changed.');
    }

    public function voidAdjustment(Request $request, StockAdjustment $adjustment): RedirectResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $adjustment->branch_id), 404);
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:2000']]);

        DB::transaction(function () use ($request, $adjustment, $data): void {
            $lockedAdjustment = StockAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedAdjustment->status === 'approved', 409, 'Only approved adjustments can be reversed.');
            $movement = $lockedAdjustment->inventoryMovements()->where('movement_type', 'adjustment')->oldest('id')->firstOrFail();
            $tank = FuelTank::query()
                ->where('branch_id', $lockedAdjustment->branch_id)
                ->whereKey($movement->fuel_tank_id)
                ->lockForUpdate()
                ->firstOrFail();
            $before = $lockedAdjustment->getAttributes();

            app(InventoryLedger::class)->record(
                $tank,
                'adjustment_void',
                -((float) $movement->signed_quantity_liters),
                $request->user(),
                $lockedAdjustment,
                now(),
                null,
                'void_reason',
            );
            $lockedAdjustment->update([
                'status' => 'voided',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $data['void_reason'],
            ]);
            $this->audit($request, 'stock-adjustment.voided', $lockedAdjustment, $before, $lockedAdjustment->getAttributes());
        });

        return redirect()->route('adjustments.index')->with('success', 'Adjustment reversed. The offsetting inventory movement was recorded.');
    }

    public function updateSale(Request $request, FuelSale $sale): RedirectResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $sale->branch_id), 404);
        abort_unless($request->user()->isOwner(), 403);
        abort_if($sale->sales_import_batch_id !== null, 403, 'Imported sales are read-only and cannot be edited.');

        $data = $request->validate([
            'liters' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'receipt_number' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', Rule::in(['cash', 'card', 'ewallet', 'other'])],
            'edit_reason' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $sale, $data): void {
            $lockedSale = FuelSale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            abort_if($lockedSale->sales_import_batch_id !== null, 403, 'Imported sales are read-only and cannot be edited.');
            abort_unless($lockedSale->status === 'completed', 409, 'Only completed sales can be edited.');
            abort_if(
                filled($data['receipt_number'] ?? null)
                    && FuelSale::query()
                        ->where('branch_id', $lockedSale->branch_id)
                        ->where('receipt_number', $data['receipt_number'])
                        ->where('id', '!=', $lockedSale->id)
                        ->exists(),
                422,
                'This receipt number is already recorded for the branch.',
            );

            if ($lockedSale->shift_id !== null) {
                abort_if(
                    Shift::query()->whereKey($lockedSale->shift_id)->where('status', 'closed')->exists(),
                    409,
                    'Sales belonging to a closed shift cannot be edited. Record a separately audited correction instead.',
                );
            }

            $saleMovement = $lockedSale->inventoryMovements()
                ->where('movement_type', 'sale')
                ->oldest('id')
                ->firstOrFail();
            $tank = app(InventoryLedger::class)->lockTank(
                (int) $lockedSale->branch_id,
                (int) $saleMovement->fuel_tank_id,
            );
            $oldLitersMilli = (int) round((float) $lockedSale->liters * 1000);
            $newLitersMilli = (int) round((float) $data['liters'] * 1000);
            $stockChangeMilli = $oldLitersMilli - $newLitersMilli;
            $before = $lockedSale->getAttributes();

            if ($stockChangeMilli !== 0) {
                app(InventoryLedger::class)->record(
                    $tank,
                    'sale_edit',
                    $stockChangeMilli / 1000,
                    $request->user(),
                    $lockedSale,
                    now(),
                    $saleMovement->unit_cost_per_liter === null ? null : (float) $saleMovement->unit_cost_per_liter,
                );
            }

            $lockedSale->update([
                'liters' => number_format($newLitersMilli / 1000, 3, '.', ''),
                'total_amount' => number_format(
                    round(($newLitersMilli / 1000) * (float) $lockedSale->price_per_liter, 2),
                    2,
                    '.',
                    '',
                ),
                'receipt_number' => $data['receipt_number'] ?: null,
                'payment_method' => $data['payment_method'] ?? null,
            ]);

            $this->audit($request, 'fuel-sale.edited', $lockedSale, $before, [
                ...$lockedSale->getAttributes(),
                'stock_change_liters' => number_format($stockChangeMilli / 1000, 3, '.', ''),
                'reason' => $data['edit_reason'],
            ]);
        });

        return back()->with('success', 'Sale updated. The stock correction and audit history were retained.');
    }

    public function voidSale(Request $request, FuelSale $sale): RedirectResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $sale->branch_id), 404);
        abort_unless(
            $request->user()->isOwner() || $sale->recorded_by !== $request->user()->id,
            403,
            'Only a branch owner can void this sale.',
        );
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:2000']]);

        DB::transaction(function () use ($request, $sale, $data): void {
            $lockedSale = FuelSale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            abort_if($lockedSale->sales_import_batch_id !== null, 403, 'Imported sales are read-only and cannot be voided.');
            abort_unless($lockedSale->status === 'completed', 409, 'This sale has already been voided.');
            $nozzle = FuelNozzle::query()->whereKey($lockedSale->fuel_nozzle_id)->firstOrFail();
            abort_unless($nozzle->branch_id === $lockedSale->branch_id, 422, 'Sale nozzle does not match the recorded branch.');
            $tank = FuelTank::query()
                ->where('branch_id', $lockedSale->branch_id)
                ->whereKey($nozzle->fuel_tank_id)
                ->lockForUpdate()
                ->firstOrFail();
            $cost = $lockedSale->inventoryMovements()
                ->where('movement_type', 'sale')
                ->value('unit_cost_per_liter');
            $before = $lockedSale->getAttributes();

            app(InventoryLedger::class)->record(
                $tank,
                'sale_void',
                (float) $lockedSale->liters,
                $request->user(),
                $lockedSale,
                now(),
                $cost === null ? null : (float) $cost,
            );
            $lockedSale->update([
                'status' => 'voided',
                'voided_by' => $request->user()->id,
                'voided_at' => now(),
                'void_reason' => $data['void_reason'],
            ]);
            $this->audit($request, 'fuel-sale.voided', $lockedSale, $before, $lockedSale->getAttributes());
        });

        return redirect()->route('sales.index')->with('success', 'Sale voided and fuel returned to the inventory ledger.');
    }

    private function audit(Request $request, string $action, Model $subject, ?array $before, ?array $after): void
    {
        AuditLog::create([
            'branch_id' => $subject->getAttribute('branch_id'),
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'occurred_at' => now(),
        ]);
    }
}

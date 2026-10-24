<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\FuelDelivery;
use App\Models\FuelNozzle;
use App\Models\FuelPrice;
use App\Models\FuelSale;
use App\Models\FuelTank;
use App\Models\StockAdjustment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FuelOperationController extends Controller
{
    public function prices(Request $request): View
    {
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
            'canManage' => in_array($request->user()->role, [UserRole::Owner, UserRole::Manager], true),
        ]);
    }

    public function sales(Request $request): View
    {
        $user = $request->user();
        $branchId = $this->branchId($request);

        return view('operations.sales', [
            'branchId' => $branchId,
            'nozzles' => FuelNozzle::visibleTo($user)
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->with('pump', 'tank.fuelProduct')
                ->get(),
            'prices' => FuelPrice::where('branch_id', $branchId)
                ->where('effective_from', '<=', now())
                ->orderByDesc('effective_from')
                ->get()
                ->unique('fuel_product_id')
                ->keyBy('fuel_product_id'),
            'sales' => FuelSale::visibleTo($user)
                ->where('branch_id', $branchId)
                ->with('fuelProduct', 'nozzle.pump', 'recorder')
                ->latest('sold_at')
                ->limit(30)
                ->get(),
        ]);
    }

    public function storeSale(Request $request): RedirectResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'fuel_nozzle_id' => ['required', 'integer'],
            'liters' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'receipt_number' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', Rule::in(['cash', 'card', 'ewallet', 'other'])],
            'sold_at' => ['required', 'date', 'before_or_equal:now'],
        ]);

        $nozzle = FuelNozzle::visibleTo($request->user())
            ->where('branch_id', $branchId)
            ->with('tank')
            ->findOrFail($data['fuel_nozzle_id']);

        abort_unless($nozzle->tank->branch_id === $branchId, 422, 'The selected nozzle is not assigned to this branch.');

        $price = FuelPrice::where('branch_id', $branchId)
            ->where('fuel_product_id', $nozzle->tank->fuel_product_id)
            ->where('effective_from', '<=', $data['sold_at'])
            ->orderByDesc('effective_from')
            ->first();

        if (! $price) {
            return back()->withInput()->withErrors([
                'fuel_nozzle_id' => 'Set an effective fuel price for this product before recording sales.',
            ]);
        }

        $sale = DB::transaction(function () use ($request, $data, $branchId, $nozzle, $price): FuelSale {
            $sale = FuelSale::create([
                'branch_id' => $branchId,
                'fuel_nozzle_id' => $nozzle->id,
                'fuel_product_id' => $nozzle->tank->fuel_product_id,
                'fuel_price_id' => $price->id,
                'receipt_number' => $data['receipt_number'] ?: null,
                'liters' => $data['liters'],
                'price_per_liter' => $price->price_per_liter,
                'total_amount' => round((float) $data['liters'] * (float) $price->price_per_liter, 2),
                'payment_method' => $data['payment_method'] ?? null,
                'sold_at' => $data['sold_at'],
                'recorded_by' => $request->user()->id,
            ]);

            $sale->inventoryMovements()->create([
                'branch_id' => $branchId,
                'fuel_tank_id' => $nozzle->fuel_tank_id,
                'movement_type' => 'sale',
                'signed_quantity_liters' => -$data['liters'],
                'recorded_by' => $request->user()->id,
                'occurred_at' => $data['sold_at'],
            ]);

            $this->audit($request, 'fuel-sale.recorded', $sale, null, $sale->getAttributes());

            return $sale;
        });

        return redirect()->route('sales.index')->with('success', 'Sale recorded: '.number_format((float) $sale->total_amount, 2).' PHP.');
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
            'canApprove' => in_array($user->role, [UserRole::Owner, UserRole::Manager], true),
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
        ]);

        $tank = FuelTank::visibleTo($request->user())->where('branch_id', $branchId)->findOrFail($data['fuel_tank_id']);
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

        $this->audit($request, 'fuel-delivery.recorded', $delivery, null, $delivery->getAttributes());

        return redirect()->route('deliveries.index')->with('success', 'Delivery recorded and sent for manager approval.');
    }

    public function approveDelivery(Request $request, FuelDelivery $delivery): RedirectResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $delivery->branch_id), 404);
        abort_unless(
            $request->user()->isOwner() || $delivery->recorded_by !== $request->user()->id,
            403,
            'A manager cannot approve a delivery they recorded.',
        );

        DB::transaction(function () use ($request, $delivery): void {
            $delivery = FuelDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            abort_unless($delivery->status === 'pending', 409, 'This delivery has already been reviewed.');
            $before = $delivery->getAttributes();
            $delivery->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $delivery->inventoryMovements()->create([
                'branch_id' => $delivery->branch_id,
                'fuel_tank_id' => $delivery->fuel_tank_id,
                'movement_type' => 'delivery',
                'signed_quantity_liters' => $delivery->delivered_liters,
                'recorded_by' => $request->user()->id,
                'occurred_at' => $delivery->received_at,
            ]);

            $this->audit($request, 'fuel-delivery.approved', $delivery, $before, $delivery->getAttributes());
        });

        return redirect()->route('deliveries.index')->with('success', 'Delivery approved and added to stock.');
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
            'canApprove' => in_array($user->role, [UserRole::Owner, UserRole::Manager], true),
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

        $tank = FuelTank::visibleTo($request->user())->where('branch_id', $branchId)->findOrFail($data['fuel_tank_id']);
        $adjustment = StockAdjustment::create([
            'branch_id' => $branchId,
            'fuel_tank_id' => $tank->id,
            'quantity_change_liters' => $data['quantity_change_liters'],
            'reason' => $data['reason'],
            'status' => 'pending',
            'requested_by' => $request->user()->id,
        ]);

        $this->audit($request, 'stock-adjustment.requested', $adjustment, null, $adjustment->getAttributes());

        return redirect()->route('adjustments.index')->with('success', 'Adjustment request sent to your branch manager.');
    }

    public function approveAdjustment(Request $request, StockAdjustment $adjustment): RedirectResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $adjustment->branch_id), 404);
        abort_unless(
            $request->user()->isOwner() || $adjustment->requested_by !== $request->user()->id,
            403,
            'A manager cannot approve their own adjustment request.',
        );

        DB::transaction(function () use ($request, $adjustment): void {
            $adjustment = StockAdjustment::whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            abort_unless($adjustment->status === 'pending', 409, 'This adjustment has already been reviewed.');
            $before = $adjustment->getAttributes();
            $adjustment->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $adjustment->inventoryMovements()->create([
                'branch_id' => $adjustment->branch_id,
                'fuel_tank_id' => $adjustment->fuel_tank_id,
                'movement_type' => 'adjustment',
                'signed_quantity_liters' => $adjustment->quantity_change_liters,
                'recorded_by' => $request->user()->id,
                'occurred_at' => now(),
            ]);

            $this->audit($request, 'stock-adjustment.approved', $adjustment, $before, $adjustment->getAttributes());
        });

        return redirect()->route('adjustments.index')->with('success', 'Adjustment approved and recorded in the stock ledger.');
    }

    private function branchId(Request $request): int
    {
        $user = $request->user();

        if ($user->role === UserRole::Owner) {
            return (int) $request->validate([
                'branch_id' => ['required', 'integer', 'exists:branches,id'],
            ])['branch_id'];
        }

        abort_unless($user->branch_id !== null, 403, 'Your account must be assigned to a branch.');

        return (int) $user->branch_id;
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

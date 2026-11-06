<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBranchContext;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FuelNozzle;
use App\Models\FuelSale;
use App\Models\FuelTank;
use App\Models\InventoryMovement;
use App\Models\NozzleReading;
use App\Models\Shift;
use App\Models\TankReading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftController extends Controller
{
    use ResolvesBranchContext;

    public function active(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $shift = Shift::query()
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->with(['opener', 'tankReadings', 'nozzleReadings'])
            ->latest('opened_at')
            ->first();

        return response()->json(['data' => $shift]);
    }

    public function open(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'opening_tank_readings' => ['required', 'array', 'min:1'],
            'opening_tank_readings.*.fuel_tank_id' => ['required', 'integer', 'distinct'],
            'opening_tank_readings.*.measured_liters' => ['required', 'numeric', 'gte:0'],
            'opening_tank_readings.*.dip_cm' => ['nullable', 'numeric', 'gte:0'],
            'opening_nozzle_readings' => ['required', 'array', 'min:1'],
            'opening_nozzle_readings.*.fuel_nozzle_id' => ['required', 'integer', 'distinct'],
            'opening_nozzle_readings.*.meter_reading' => ['required', 'numeric', 'gte:0'],
        ]);

        $shift = DB::transaction(function () use ($request, $data, $branchId): Shift {
            $branch = Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
            abort_unless($branch->is_active, 422, 'This branch is inactive.');
            abort_if(
                Shift::query()->where('branch_id', $branchId)->where('status', 'open')->exists(),
                409,
                'This branch already has an open shift.',
            );

            $tanks = FuelTank::query()->where('branch_id', $branchId)->where('is_active', true)->get();
            $nozzles = FuelNozzle::query()->where('branch_id', $branchId)->where('is_active', true)->get();
            $tankIds = collect($data['opening_tank_readings'])->pluck('fuel_tank_id')->map(fn ($id) => (int) $id)->sort()->values();
            $nozzleIds = collect($data['opening_nozzle_readings'])->pluck('fuel_nozzle_id')->map(fn ($id) => (int) $id)->sort()->values();

            if ($tankIds->all() !== $tanks->pluck('id')->sort()->values()->all()
                || $nozzleIds->all() !== $nozzles->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'opening_tank_readings' => 'Opening readings must include every active tank and nozzle in this branch exactly once.',
                ]);
            }

            foreach ($data['opening_tank_readings'] as $reading) {
                $tank = $tanks->firstWhere('id', (int) $reading['fuel_tank_id']);
                if ((float) $reading['measured_liters'] > (float) $tank->capacity_liters) {
                    throw ValidationException::withMessages([
                        'opening_tank_readings' => "Tank {$tank->code} reading exceeds its capacity.",
                    ]);
                }
            }

            $shift = Shift::create([
                'branch_id' => $branchId,
                'opened_by' => $request->user()->id,
                'opened_at' => now(),
                'status' => 'open',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['opening_tank_readings'] as $reading) {
                TankReading::create([
                    'shift_id' => $shift->id,
                    'fuel_tank_id' => $reading['fuel_tank_id'],
                    'reading_type' => 'opening',
                    'dip_cm' => $reading['dip_cm'] ?? null,
                    'measured_liters' => $reading['measured_liters'],
                    'recorded_by' => $request->user()->id,
                    'recorded_at' => now(),
                ]);
            }

            foreach ($data['opening_nozzle_readings'] as $reading) {
                $previousClose = NozzleReading::query()
                    ->where('fuel_nozzle_id', $reading['fuel_nozzle_id'])
                    ->where('reading_type', 'closing')
                    ->latest('recorded_at')
                    ->first();
                if ($previousClose && (float) $reading['meter_reading'] < (float) $previousClose->meter_reading) {
                    throw ValidationException::withMessages([
                        'opening_nozzle_readings' => 'An opening meter reading cannot be less than the previous closing reading.',
                    ]);
                }

                NozzleReading::create([
                    'shift_id' => $shift->id,
                    'fuel_nozzle_id' => $reading['fuel_nozzle_id'],
                    'reading_type' => 'opening',
                    'meter_reading' => $reading['meter_reading'],
                    'recorded_by' => $request->user()->id,
                    'recorded_at' => now(),
                ]);
            }

            $this->audit($request, $shift, 'shift.opened', null, $shift->getAttributes());

            return $shift;
        });

        return response()->json(['data' => $shift->load('tankReadings', 'nozzleReadings')], 201);
    }

    public function close(Request $request, Shift $shift): JsonResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $shift->branch_id), 404);
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'closing_tank_readings' => ['required', 'array', 'min:1'],
            'closing_tank_readings.*.fuel_tank_id' => ['required', 'integer', 'distinct'],
            'closing_tank_readings.*.measured_liters' => ['required', 'numeric', 'gte:0'],
            'closing_tank_readings.*.dip_cm' => ['nullable', 'numeric', 'gte:0'],
            'closing_nozzle_readings' => ['required', 'array', 'min:1'],
            'closing_nozzle_readings.*.fuel_nozzle_id' => ['required', 'integer', 'distinct'],
            'closing_nozzle_readings.*.meter_reading' => ['required', 'numeric', 'gte:0'],
        ]);

        $closedShift = DB::transaction(function () use ($request, $data, $shift): Shift {
            $lockedShift = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedShift->status === 'open', 409, 'This shift is already closed.');

            $openingTanks = $lockedShift->tankReadings()->where('reading_type', 'opening')->get()->keyBy('fuel_tank_id');
            $openingNozzles = $lockedShift->nozzleReadings()->where('reading_type', 'opening')->get()->keyBy('fuel_nozzle_id');
            $tankIds = collect($data['closing_tank_readings'])->pluck('fuel_tank_id')->map(fn ($id) => (int) $id)->sort()->values();
            $nozzleIds = collect($data['closing_nozzle_readings'])->pluck('fuel_nozzle_id')->map(fn ($id) => (int) $id)->sort()->values();

            if ($tankIds->all() !== $openingTanks->keys()->map(fn ($id) => (int) $id)->sort()->values()->all()
                || $nozzleIds->all() !== $openingNozzles->keys()->map(fn ($id) => (int) $id)->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'closing_tank_readings' => 'Closing readings must include every active tank and nozzle in this branch exactly once.',
                ]);
            }

            $tanks = FuelTank::query()->whereIn('id', $openingTanks->keys())->get()->keyBy('id');
            $summary = ['nozzles' => [], 'tanks' => []];
            $now = now();

            foreach ($data['closing_tank_readings'] as $reading) {
                $tank = $tanks->get((int) $reading['fuel_tank_id']);
                $closingLiters = (float) $reading['measured_liters'];
                if ($closingLiters > (float) $tank->capacity_liters) {
                    throw ValidationException::withMessages([
                        'closing_tank_readings' => "Tank {$tank->code} reading exceeds its capacity.",
                    ]);
                }

                $openingLiters = (float) $openingTanks->get($tank->id)?->measured_liters;
                $movementDelta = (float) InventoryMovement::query()
                    ->where('fuel_tank_id', $tank->id)
                    ->where('occurred_at', '>=', $lockedShift->opened_at)
                    ->where('occurred_at', '<=', $now)
                    ->whereIn('movement_type', ['delivery', 'adjustment', 'stock_reconciliation', 'sale_void'])
                    ->sum('signed_quantity_liters');
                $sales = (float) FuelSale::query()
                    ->where('shift_id', $lockedShift->id)
                    ->whereIn('fuel_nozzle_id', FuelNozzle::query()
                        ->select('id')
                        ->where('fuel_tank_id', $tank->id))
                    ->sum('liters');
                $expected = $openingLiters + $movementDelta - $sales;
                $summary['tanks'][$tank->id] = [
                    'opening_liters' => round($openingLiters, 3),
                    'expected_liters' => round($expected, 3),
                    'measured_liters' => round($closingLiters, 3),
                    'variance_liters' => round($closingLiters - $expected, 3),
                ];

                TankReading::create([
                    'shift_id' => $lockedShift->id,
                    'fuel_tank_id' => $tank->id,
                    'reading_type' => 'closing',
                    'dip_cm' => $reading['dip_cm'] ?? null,
                    'measured_liters' => $closingLiters,
                    'recorded_by' => $request->user()->id,
                    'recorded_at' => $now,
                ]);
            }

            foreach ($data['closing_nozzle_readings'] as $reading) {
                $nozzleId = (int) $reading['fuel_nozzle_id'];
                $opening = $openingNozzles->get($nozzleId);
                $meter = (float) $reading['meter_reading'];
                abort_if($meter < (float) $opening->meter_reading, 422, 'A closing meter reading cannot be less than its opening reading.');
                $meterVolume = $meter - (float) $opening->meter_reading;
                $recordedSales = (float) FuelSale::query()
                    ->where('shift_id', $lockedShift->id)
                    ->where('fuel_nozzle_id', $nozzleId)
                    ->sum('liters');
                $summary['nozzles'][$nozzleId] = [
                    'meter_volume_liters' => round($meterVolume, 3),
                    'recorded_sales_liters' => round($recordedSales, 3),
                    'variance_liters' => round($meterVolume - $recordedSales, 3),
                ];

                NozzleReading::create([
                    'shift_id' => $lockedShift->id,
                    'fuel_nozzle_id' => $nozzleId,
                    'reading_type' => 'closing',
                    'meter_reading' => $meter,
                    'recorded_by' => $request->user()->id,
                    'recorded_at' => $now,
                ]);
            }

            $before = $lockedShift->getAttributes();
            $lockedShift->update([
                'closed_by' => $request->user()->id,
                'closed_at' => $now,
                'status' => 'closed',
                'notes' => $data['notes'] ?? $lockedShift->notes,
                'closing_summary' => $summary,
            ]);
            $this->audit($request, $lockedShift, 'shift.closed', $before, $lockedShift->getAttributes());

            return $lockedShift->load('tankReadings', 'nozzleReadings');
        });

        return response()->json(['data' => $closedShift], 200);
    }

    private function audit(Request $request, Shift $shift, string $action, ?array $before, ?array $after): void
    {
        AuditLog::create([
            'branch_id' => $shift->branch_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => Shift::class,
            'subject_id' => $shift->id,
            'before' => $before,
            'after' => $after,
            'occurred_at' => now(),
        ]);
    }
}

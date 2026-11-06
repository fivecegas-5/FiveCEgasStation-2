<?php

namespace App\Services;

use App\Models\FuelTank;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryLedger
{
    public function lockTank(int $branchId, int $tankId): FuelTank
    {
        return FuelTank::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->whereKey($tankId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function balance(FuelTank $tank): float
    {
        return (float) $tank->movements()->sum('signed_quantity_liters');
    }

    public function assertBalanceWithinCapacity(FuelTank $tank, float $change, string $field = 'liters'): void
    {
        $balance = $this->balance($tank);
        $resultingBalance = $balance + $change;

        if ($resultingBalance < -0.0005) {
            throw ValidationException::withMessages([
                $field => 'This transaction exceeds the fuel currently in the tank.',
            ]);
        }

        if ($resultingBalance - (float) $tank->capacity_liters > 0.0005) {
            throw ValidationException::withMessages([
                $field => 'This transaction would exceed the tank capacity.',
            ]);
        }
    }

    public function weightedAverageCost(FuelTank $tank, Carbon|string|null $asOf = null): ?float
    {
        $quantity = 0.0;
        $knownValue = 0.0;
        $unknownQuantity = 0.0;

        foreach ($tank->movements()
            ->when($asOf !== null, fn ($query) => $query->where('occurred_at', '<=', $asOf))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get() as $movement) {
            $change = (float) $movement->signed_quantity_liters;

            if ($change > 0) {
                if ($movement->unit_cost_per_liter === null) {
                    $unknownQuantity += $change;
                } else {
                    $knownValue += $change * (float) $movement->unit_cost_per_liter;
                }

                $quantity += $change;

                continue;
            }

            if ($change < 0 && $quantity > 0) {
                $sold = min(abs($change), $quantity);
                $unknownRatio = $unknownQuantity / $quantity;
                $unknownQuantity = max(0, $unknownQuantity - ($sold * $unknownRatio));
                $knownValue *= max(0, ($quantity - $sold) / $quantity);
                $quantity = max(0, $quantity - $sold);
            }
        }

        if ($quantity <= 0.0005 || $unknownQuantity > 0.0005) {
            return null;
        }

        return round($knownValue / $quantity, 2);
    }

    public function record(
        FuelTank $tank,
        string $movementType,
        float $quantityChange,
        User $actor,
        object $source,
        Carbon|string $occurredAt,
        ?float $unitCostPerLiter = null,
        string $quantityField = 'liters',
    ): InventoryMovement {
        $previousBalance = $this->balance($tank);
        $this->assertBalanceWithinCapacity($tank, $quantityChange, $quantityField);
        $movement = $source->inventoryMovements()->create([
            'branch_id' => $tank->branch_id,
            'fuel_tank_id' => $tank->id,
            'movement_type' => $movementType,
            'signed_quantity_liters' => $quantityChange,
            'unit_cost_per_liter' => $unitCostPerLiter,
            'recorded_by' => $actor->id,
            'occurred_at' => $occurredAt,
        ]);

        $threshold = $tank->low_stock_threshold_liters === null
            ? null
            : (float) $tank->low_stock_threshold_liters;
        $newBalance = $previousBalance + $quantityChange;

        if ($threshold !== null && $previousBalance > $threshold && $newBalance <= $threshold) {
            DB::afterCommit(
                fn () => app(LowStockAlertNotifier::class)->notify($tank, $newBalance, $threshold),
            );
        }

        return $movement;
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONFIGURATION = [
        'manaoag' => [
            'tanks' => [
                ['code' => 'B1-T01', 'product' => 'Unleaded', 'capacity' => 5000],
                ['code' => 'B1-T02', 'product' => 'Premium', 'capacity' => 8000],
                ['code' => 'B1-T03', 'product' => 'Diesel', 'capacity' => 8000],
                ['code' => 'B1-P2-T02', 'product' => 'Premium', 'capacity' => 5000],
                ['code' => 'B1-P3-T01', 'product' => 'Unleaded', 'capacity' => 5000],
                ['code' => 'B1-P3-T03', 'product' => 'Diesel', 'capacity' => 5000],
            ],
            'nozzles' => [
                'B1-P2-N1' => 'B1-P2-T02',
                'B1-P2-N2' => 'B1-P2-T02',
                'B1-P3-N1' => 'B1-P3-T01',
                'B1-P3-N2' => 'B1-P3-T03',
            ],
        ],
        'san-fabian' => [
            'tanks' => [
                ['code' => 'B2-T01', 'product' => 'Unleaded', 'capacity' => 6000],
                ['code' => 'B2-T02', 'product' => 'Premium', 'capacity' => 6000],
                ['code' => 'B2-T03', 'product' => 'Diesel', 'capacity' => 6000],
                ['code' => 'B2-P2-T01', 'product' => 'Unleaded', 'capacity' => 6000],
                ['code' => 'B2-P2-T02', 'product' => 'Premium', 'capacity' => 6000],
                ['code' => 'B2-P2-T03', 'product' => 'Diesel', 'capacity' => 6000],
            ],
            'nozzles' => [
                'B2-P2-N1' => 'B2-P2-T03',
                'B2-P2-N2' => 'B2-P2-T03',
                'B2-P2-N3' => 'B2-P2-T01',
                'B2-P2-N4' => 'B2-P2-T01',
                'B2-P2-N5' => 'B2-P2-T02',
                'B2-P2-N6' => 'B2-P2-T02',
            ],
        ],
    ];

    public function up(): void
    {
        if (! DB::table('branches')->exists()) {
            return;
        }

        DB::transaction(function (): void {
            foreach (self::CONFIGURATION as $branchCode => $configuration) {
                $branch = DB::table('branches')->where('code', $branchCode)->first();
                if (! $branch) {
                    continue;
                }

                $this->migrateBranch((int) $branch->id, $configuration);
            }
        });
    }

    public function down(): void
    {
        // Tank mappings and stock ledgers cannot be safely merged back automatically.
    }

    private function migrateBranch(int $branchId, array $configuration): void
    {
        $products = DB::table('fuel_products')->whereIn(
            'name',
            array_column($configuration['tanks'], 'product'),
        )->pluck('id', 'name');

        foreach ($configuration['tanks'] as $tankSetup) {
            $productId = $products->get($tankSetup['product']);
            if ($productId === null) {
                throw new RuntimeException("Cannot split station tanks: fuel product [{$tankSetup['product']}] is missing.");
            }

            $tank = DB::table('fuel_tanks')
                ->where('branch_id', $branchId)
                ->where('code', $tankSetup['code'])
                ->first();

            if (! $tank) {
                DB::table('fuel_tanks')->insert([
                    'branch_id' => $branchId,
                    'fuel_product_id' => $productId,
                    'code' => $tankSetup['code'],
                    'capacity_liters' => $tankSetup['capacity'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

            if ((int) $tank->fuel_product_id !== (int) $productId) {
                throw new RuntimeException("Cannot split station tanks: tank [{$tankSetup['code']}] is assigned to a different fuel.");
            }

            $balance = (float) DB::table('inventory_movements')
                ->where('fuel_tank_id', $tank->id)
                ->sum('signed_quantity_liters');
            if ($balance < -0.0005 || $balance > (float) $tankSetup['capacity'] + 0.0005) {
                throw new RuntimeException("Cannot reduce tank [{$tankSetup['code']}] to {$tankSetup['capacity']} L because its current ledger balance is {$balance} L. Reconcile stock first.");
            }

            DB::table('fuel_tanks')->where('id', $tank->id)->update([
                'capacity_liters' => $tankSetup['capacity'],
                'low_stock_threshold_liters' => $tank->low_stock_threshold_liters === null
                    ? null
                    : min((float) $tank->low_stock_threshold_liters, (float) $tankSetup['capacity']),
                'updated_at' => now(),
            ]);
        }

        $tankIds = DB::table('fuel_tanks')
            ->where('branch_id', $branchId)
            ->whereIn('code', array_column($configuration['tanks'], 'code'))
            ->pluck('id', 'code');

        foreach ($configuration['nozzles'] as $nozzleCode => $tankCode) {
            $nozzle = DB::table('fuel_nozzles')
                ->where('branch_id', $branchId)
                ->where('code', $nozzleCode)
                ->first();
            if (! $nozzle) {
                throw new RuntimeException("Cannot split station tanks: nozzle [{$nozzleCode}] is missing.");
            }
            if (! $tankIds->has($tankCode)) {
                throw new RuntimeException("Cannot split station tanks: target tank [{$tankCode}] is missing.");
            }

            $targetTankId = (int) $tankIds->get($tankCode);
            if ((int) $nozzle->fuel_tank_id !== $targetTankId
                && (DB::table('fuel_sales')->where('fuel_nozzle_id', $nozzle->id)->exists()
                    || DB::table('nozzle_readings')->where('fuel_nozzle_id', $nozzle->id)->exists())) {
                throw new RuntimeException(
                    "Cannot remap nozzle [{$nozzleCode}] because it has recorded sales or shift readings. Preserve its history and reconcile this equipment manually.",
                );
            }

            DB::table('fuel_nozzles')->where('id', $nozzle->id)->update([
                'fuel_tank_id' => $targetTankId,
                'updated_at' => now(),
            ]);
        }
    }
};

<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\FuelNozzle;
use App\Models\FuelPrice;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelTank;
use App\Models\User;
use App\Services\InventoryLedger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StationSetupSeeder extends Seeder
{
    private const OPENING_BALANCE_AT = '2026-10-29 00:00:00';

    public function run(): void
    {
        $this->call([
            BranchSeeder::class,
            FuelProductSeeder::class,
        ]);

        $branches = Branch::query()->get()->keyBy('code');
        $products = FuelProduct::query()->whereIn('name', ['Unleaded', 'Premium', 'Diesel'])->get()->keyBy('name');

        $configuration = [
            'manaoag' => [
                'tanks' => [
                    ['code' => 'B1-T01', 'product' => 'Unleaded', 'capacity' => 5000, 'opening' => 3000, 'price' => 84.52],
                    ['code' => 'B1-T02', 'product' => 'Premium', 'capacity' => 8000, 'opening' => 3000, 'price' => 85.26],
                    ['code' => 'B1-T03', 'product' => 'Diesel', 'capacity' => 8000, 'opening' => 4000, 'price' => 87.76],
                    ['code' => 'B1-P2-T02', 'product' => 'Premium', 'capacity' => 5000, 'opening' => 0, 'price' => 85.26],
                    ['code' => 'B1-P3-T01', 'product' => 'Unleaded', 'capacity' => 5000, 'opening' => 0, 'price' => 84.52],
                    ['code' => 'B1-P3-T03', 'product' => 'Diesel', 'capacity' => 5000, 'opening' => 0, 'price' => 87.76],
                ],
                'pumps' => [
                    [
                        'code' => 'B1-P1',
                        'label' => 'Tatsuno P#1',
                        'nozzles' => [
                            ['code' => 'B1-P1-N1', 'product' => 'Diesel', 'tank' => 'B1-T03'],
                            ['code' => 'B1-P1-N2', 'product' => 'Diesel', 'tank' => 'B1-T03'],
                            ['code' => 'B1-P1-N3', 'product' => 'Premium', 'tank' => 'B1-T02'],
                            ['code' => 'B1-P1-N4', 'product' => 'Premium', 'tank' => 'B1-T02'],
                        ],
                    ],
                    [
                        'code' => 'B1-P2',
                        'label' => 'Tatsuno P#2',
                        'nozzles' => [
                            ['code' => 'B1-P2-N1', 'product' => 'Premium', 'tank' => 'B1-P2-T02'],
                            ['code' => 'B1-P2-N2', 'product' => 'Premium', 'tank' => 'B1-P2-T02'],
                            ['code' => 'B1-P2-N3', 'product' => 'Unleaded', 'tank' => 'B1-T01'],
                            ['code' => 'B1-P2-N4', 'product' => 'Unleaded', 'tank' => 'B1-T01'],
                        ],
                    ],
                    [
                        'code' => 'B1-P3',
                        'label' => 'Tatsuno P#3',
                        'nozzles' => [
                            ['code' => 'B1-P3-N1', 'product' => 'Unleaded', 'tank' => 'B1-P3-T01'],
                            ['code' => 'B1-P3-N2', 'product' => 'Diesel', 'tank' => 'B1-P3-T03'],
                        ],
                    ],
                ],
            ],
            'san-fabian' => [
                'tanks' => [
                    ['code' => 'B2-T01', 'product' => 'Unleaded', 'capacity' => 6000, 'opening' => 3000, 'price' => 84.57],
                    ['code' => 'B2-T02', 'product' => 'Premium', 'capacity' => 6000, 'opening' => 3000, 'price' => 85.26],
                    ['code' => 'B2-T03', 'product' => 'Diesel', 'capacity' => 6000, 'opening' => 4000, 'price' => 87.16],
                    ['code' => 'B2-P2-T01', 'product' => 'Unleaded', 'capacity' => 6000, 'opening' => 0, 'price' => 84.57],
                    ['code' => 'B2-P2-T02', 'product' => 'Premium', 'capacity' => 6000, 'opening' => 0, 'price' => 85.26],
                    ['code' => 'B2-P2-T03', 'product' => 'Diesel', 'capacity' => 6000, 'opening' => 0, 'price' => 87.16],
                ],
                'pumps' => [
                    [
                        'code' => 'B2-P1',
                        'label' => 'Tatsuno P#1',
                        'nozzles' => [
                            ['code' => 'B2-P1-N1', 'product' => 'Diesel', 'tank' => 'B2-T03'],
                            ['code' => 'B2-P1-N2', 'product' => 'Diesel', 'tank' => 'B2-T03'],
                            ['code' => 'B2-P1-N3', 'product' => 'Unleaded', 'tank' => 'B2-T01'],
                            ['code' => 'B2-P1-N4', 'product' => 'Unleaded', 'tank' => 'B2-T01'],
                            ['code' => 'B2-P1-N5', 'product' => 'Premium', 'tank' => 'B2-T02'],
                            ['code' => 'B2-P1-N6', 'product' => 'Premium', 'tank' => 'B2-T02'],
                        ],
                    ],
                    [
                        'code' => 'B2-P2',
                        'label' => 'Tatsuno P#2',
                        'nozzles' => [
                            ['code' => 'B2-P2-N1', 'product' => 'Diesel', 'tank' => 'B2-P2-T03'],
                            ['code' => 'B2-P2-N2', 'product' => 'Diesel', 'tank' => 'B2-P2-T03'],
                            ['code' => 'B2-P2-N3', 'product' => 'Unleaded', 'tank' => 'B2-P2-T01'],
                            ['code' => 'B2-P2-N4', 'product' => 'Unleaded', 'tank' => 'B2-P2-T01'],
                            ['code' => 'B2-P2-N5', 'product' => 'Premium', 'tank' => 'B2-P2-T02'],
                            ['code' => 'B2-P2-N6', 'product' => 'Premium', 'tank' => 'B2-P2-T02'],
                        ],
                    ],
                ],
            ],
        ];

        DB::transaction(function () use ($branches, $products, $configuration): void {
            $this->deactivateUnusedLegacyProducts();
            foreach ($configuration as $branchCode => $branchSetup) {
                $branch = $branches->get($branchCode);
                if (! $branch) {
                    throw new RuntimeException("Required branch [{$branchCode}] is missing.");
                }

                $actor = $this->setupActor($branch);
                $tanks = [];
                foreach ($branchSetup['tanks'] as $tankSetup) {
                    $product = $products->get($tankSetup['product']);
                    if (! $product) {
                        throw new RuntimeException("Required fuel product [{$tankSetup['product']}] is missing.");
                    }

                    $tank = FuelTank::query()->firstOrCreate(
                        ['branch_id' => $branch->id, 'code' => $tankSetup['code']],
                        [
                            'fuel_product_id' => $product->id,
                            'capacity_liters' => $tankSetup['capacity'],
                            'is_active' => true,
                        ],
                    );
                    if ((int) $tank->fuel_product_id !== (int) $product->id
                        || (float) $tank->capacity_liters !== (float) $tankSetup['capacity']) {
                        throw new RuntimeException("Tank [{$tankSetup['code']}] already exists with different fuel or capacity.");
                    }

                    $this->ensureOpeningBalance($tank, (float) $tankSetup['opening'], $actor);
                    $this->ensureCurrentPrice($branch, $product, (float) $tankSetup['price'], $actor);
                    $tanks[$tankSetup['code']] = $tank;
                }

                foreach ($branchSetup['pumps'] as $pumpSetup) {
                    $pump = FuelPump::query()->firstOrCreate(
                        ['branch_id' => $branch->id, 'code' => $pumpSetup['code']],
                        ['label' => $pumpSetup['label'], 'is_active' => true],
                    );
                    if ($pump->label !== $pumpSetup['label']) {
                        if ($pump->label !== null) {
                            throw new RuntimeException("Pump [{$pumpSetup['code']}] already has a different physical label.");
                        }
                        $pump->update(['label' => $pumpSetup['label']]);
                    }

                    foreach ($pumpSetup['nozzles'] as $nozzleSetup) {
                        $product = $products->get($nozzleSetup['product']);
                        $tank = $tanks[$nozzleSetup['tank']] ?? null;
                        if (! $product || ! $tank || (int) $tank->fuel_product_id !== (int) $product->id) {
                            throw new RuntimeException("Nozzle [{$nozzleSetup['code']}] has an invalid tank/fuel mapping.");
                        }

                        $nozzle = FuelNozzle::query()->firstOrCreate(
                            ['fuel_pump_id' => $pump->id, 'code' => $nozzleSetup['code']],
                            [
                                'branch_id' => $branch->id,
                                'fuel_tank_id' => $tank->id,
                                'is_active' => true,
                            ],
                        );
                        if ((int) $nozzle->branch_id !== (int) $branch->id
                            || (int) $nozzle->fuel_tank_id !== (int) $tank->id) {
                            throw new RuntimeException("Nozzle [{$nozzleSetup['code']}] already has a different branch/tank mapping.");
                        }
                    }
                }
            }
        });
    }

    private function setupActor(Branch $branch): User
    {
        return User::query()
            ->where('role', UserRole::Owner)
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('branch_id', $branch->id)->orWhereNull('branch_id'))
            ->orderByRaw('branch_id IS NULL')
            ->first()
            ?? throw new RuntimeException("An active owner must exist for branch [{$branch->code}] before station setup can be seeded.");
    }

    private function ensureOpeningBalance(FuelTank $tank, float $openingStock, User $actor): void
    {
        $openingMovements = $tank->movements()->where('movement_type', 'opening_balance')->get();
        if ($openingMovements->count() > 1) {
            throw new RuntimeException("Tank [{$tank->code}] has multiple opening-balance entries; review its ledger before seeding.");
        }
        if ($openingMovements->isNotEmpty()) {
            if (abs((float) $openingMovements->first()->signed_quantity_liters - $openingStock) > 0.0005) {
                throw new RuntimeException("Tank [{$tank->code}] already has a different opening balance.");
            }

            return;
        }
        if ($tank->movements()->exists()) {
            throw new RuntimeException("Tank [{$tank->code}] already has ledger history but no opening-balance entry.");
        }

        app(InventoryLedger::class)->record(
            $tank,
            'opening_balance',
            $openingStock,
            $actor,
            $tank,
            Carbon::parse(self::OPENING_BALANCE_AT, config('app.timezone')),
        );
    }

    private function ensureCurrentPrice(Branch $branch, FuelProduct $product, float $expected, User $actor): void
    {
        $currentPrice = FuelPrice::query()
            ->where('branch_id', $branch->id)
            ->where('fuel_product_id', $product->id)
            ->where('effective_from', '<=', now())
            ->latest('effective_from')
            ->first();

        if ($currentPrice) {
            if (abs((float) $currentPrice->price_per_liter - $expected) > 0.0005) {
                throw new RuntimeException("Current price for {$branch->name} / {$product->name} differs from the verified setup value.");
            }

            return;
        }

        FuelPrice::query()->create([
            'branch_id' => $branch->id,
            'fuel_product_id' => $product->id,
            'price_per_liter' => $expected,
            'effective_from' => now()->startOfSecond(),
            'set_by' => $actor->id,
        ]);
    }

    private function deactivateUnusedLegacyProducts(): void
    {
        foreach (['Unleaded 91', 'Premium 95'] as $name) {
            $product = FuelProduct::query()->where('name', $name)->first();
            if (! $product) {
                continue;
            }
            if ($product->fuelTanks()->exists() || $product->prices()->exists() || $product->sales()->exists()) {
                continue;
            }

            $product->update(['is_active' => false]);
        }
    }
}

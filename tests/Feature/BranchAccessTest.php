<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\FuelNozzle;
use App\Models\FuelPrice;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelSale;
use App\Models\FuelTank;
use App\Models\InventoryMovement;
use App\Models\User;
use Database\Seeders\BranchSeeder;
use Database\Seeders\FuelProductSeeder;
use Database\Seeders\StationSetupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_seeder_creates_the_two_client_locations_idempotently(): void
    {
        $seeder = app(BranchSeeder::class);
        $seeder->run();
        $seeder->run();

        $this->assertSame(2, Branch::count());
        $this->assertSame(
            ['manaoag', 'san-fabian'],
            Branch::orderBy('code')->pluck('code')->all(),
        );
    }

    public function test_fuel_product_seeder_is_repeatable_and_creates_standard_grades(): void
    {
        $seeder = app(FuelProductSeeder::class);
        $seeder->run();
        $seeder->run();

        $this->assertSame(
            ['Diesel', 'Premium', 'Unleaded'],
            FuelProduct::orderBy('name')->pluck('name')->all(),
        );
        $this->assertSame(3, FuelProduct::where('unit', 'liter')->count());
    }

    public function test_station_setup_seeder_creates_verified_equipment_prices_and_opening_stock_idempotently(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => null]);

        $seeder = app(StationSetupSeeder::class);
        $seeder->run();
        $seeder->run();

        $this->assertSame(2, Branch::count());
        $this->assertSame(12, FuelTank::count());
        $this->assertSame(5, FuelPump::count());
        $this->assertSame(22, FuelNozzle::count());
        $this->assertSame(6, FuelPrice::count());
        $this->assertSame(12, InventoryMovement::where('movement_type', 'opening_balance')->count());
        $this->assertSame(
            'Cabanbanan, Manaoag, Pangasinan',
            Branch::where('code', 'manaoag')->value('address'),
        );
        $this->assertSame(
            'Bolasi, San Fabian, Pangasinan',
            Branch::where('code', 'san-fabian')->value('address'),
        );
        $this->assertDatabaseHas('fuel_tanks', [
            'code' => 'B1-T03',
            'capacity_liters' => '8000.000',
        ]);
        $this->assertDatabaseHas('fuel_tanks', [
            'code' => 'B1-P2-T02',
            'capacity_liters' => '5000.000',
        ]);
        $this->assertDatabaseHas('fuel_tanks', [
            'code' => 'B2-P2-T03',
            'capacity_liters' => '6000.000',
        ]);
        $this->assertSame(
            3000.0,
            (float) FuelTank::where('code', 'B1-T01')->firstOrFail()->movements()->sum('signed_quantity_liters'),
        );
        $this->assertSame(
            4000.0,
            (float) FuelTank::where('code', 'B2-T03')->firstOrFail()->movements()->sum('signed_quantity_liters'),
        );
        $this->assertDatabaseHas('inventory_movements', [
            'movement_type' => 'opening_balance',
            'signed_quantity_liters' => '4000.000',
            'occurred_at' => '2026-10-29 00:00:00',
        ]);
        $dieselNozzle = FuelNozzle::with('pump', 'tank.fuelProduct')->where('code', 'B1-P1-N1')->firstOrFail();
        $this->assertSame('Diesel', $dieselNozzle->tank->fuelProduct->name);
        $this->assertSame('B1-T03', $dieselNozzle->tank->code);
        $this->assertSame('Tatsuno P#1', $dieselNozzle->pump->label);
        $this->assertSame('B1-P2-T02', FuelNozzle::where('code', 'B1-P2-N1')->firstOrFail()->tank->code);
        $this->assertSame('B1-P3-T01', FuelNozzle::where('code', 'B1-P3-N1')->firstOrFail()->tank->code);
        $this->assertSame('B1-P3-T03', FuelNozzle::where('code', 'B1-P3-N2')->firstOrFail()->tank->code);
        $this->assertSame('B2-P2-T01', FuelNozzle::where('code', 'B2-P2-N3')->firstOrFail()->tank->code);
        $this->assertSame(
            0.0,
            (float) FuelTank::where('code', 'B1-P2-T02')->firstOrFail()->movements()->sum('signed_quantity_liters'),
        );
        $this->assertDatabaseHas('fuel_nozzles', ['code' => 'B2-P2-N6']);
        $this->assertDatabaseHas('fuel_prices', [
            'branch_id' => Branch::where('code', 'san-fabian')->value('id'),
            'fuel_product_id' => FuelProduct::where('name', 'Diesel')->value('id'),
            'price_per_liter' => '87.16',
        ]);

        $this->actingAs($owner)->get(route('setup.index'))
            ->assertOk()
            ->assertSee('B1-P2-T02')
            ->assertSee('Tatsuno P#2');
    }

    public function test_owner_can_register_a_tank_with_zero_measured_stock_and_it_is_ledgered(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => null]);
        app(StationSetupSeeder::class)->run();
        $branch = Branch::where('code', 'manaoag')->firstOrFail();
        $product = FuelProduct::where('name', 'Unleaded')->firstOrFail();

        $this->actingAs($owner)->post(route('setup.tanks'), [
            'branch_id' => $branch->id,
            'code' => 'B1-P4-T01',
            'fuel_product_id' => $product->id,
            'capacity_liters' => 5000,
            'opening_stock_liters' => 0,
        ])->assertRedirect();

        $tank = FuelTank::where('code', 'B1-P4-T01')->firstOrFail();
        $this->assertSame(
            0.0,
            (float) $tank->movements()->sum('signed_quantity_liters'),
        );
        $this->assertDatabaseHas('inventory_movements', [
            'fuel_tank_id' => $tank->id,
            'movement_type' => 'opening_balance',
            'signed_quantity_liters' => '0.000',
        ]);
    }

    public function test_workspace_api_returns_real_branch_inventory_and_sales_totals(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => null]);
        app(StationSetupSeeder::class)->run();
        FuelTank::where('code', 'B1-T01')->update(['low_stock_threshold_liters' => 500]);

        $this->actingAs($owner)->getJson(route('api.workspace', ['per_page' => 1]))
            ->assertOk()
            ->assertJsonPath('active_branch_id', Branch::where('code', 'manaoag')->value('id'))
            ->assertJsonPath('summary.stock_liters', 10000)
            ->assertJsonPath('pagination.sales.per_page', 1)
            ->assertJsonPath('pagination.sales.total', 0)
            ->assertJsonCount(6, 'inventory')
            ->assertJsonFragment(['code' => 'B1-T01', 'low_stock_threshold_liters' => 500])
            ->assertJsonFragment([
                'code' => 'B1-P2-T02',
                'stock_liters' => 0,
                'capacity_liters' => 5000,
            ])
            ->assertJsonFragment([
                'code' => 'B1-P1',
                'label' => 'Tatsuno P#1',
            ]);
    }

    public function test_staff_can_filter_sales_history_to_sales_they_recorded(): void
    {
        $branch = Branch::create(['code' => 'manaoag', 'name' => 'Manaoag']);
        $staff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $branch->id]);
        $ownSale = $this->createSale($branch, 'OWN-001');
        $ownSale->update(['recorded_by' => $staff->id]);
        $otherSale = $ownSale->replicate();
        $otherSale->receipt_number = 'OTHER-001';
        $otherSale->recorded_by = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $branch->id])->id;
        $otherSale->save();

        $this->actingAs($staff)
            ->getJson(route('api.workspace', ['my_sales' => 1]))
            ->assertOk()
            ->assertJsonPath('pagination.sales.total', 1)
            ->assertJsonPath('sales.0.receipt', 'OWN-001')
            ->assertJsonPath('sales.0.recorder', $staff->name);
    }

    public function test_workspace_inventory_is_limited_to_the_selected_branch(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => null]);
        app(StationSetupSeeder::class)->run();
        $manaoag = Branch::where('code', 'manaoag')->firstOrFail();

        $this->actingAs($owner)
            ->withSession(['active_branch_id' => $manaoag->id])
            ->getJson(route('api.workspace'))
            ->assertOk()
            ->assertJsonPath('active_branch_id', $manaoag->id)
            ->assertJsonCount(6, 'inventory')
            ->assertJsonFragment(['branch' => 'Manaoag', 'code' => 'B1-T01'])
            ->assertJsonMissing(['branch' => 'San Fabian', 'code' => 'B2-T01']);
    }

    public function test_station_setup_only_includes_equipment_and_prices_for_the_selected_branch(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => null]);
        app(StationSetupSeeder::class)->run();
        $sanFabian = Branch::where('code', 'san-fabian')->firstOrFail();

        $this->actingAs($owner)
            ->withSession(['active_branch_id' => $sanFabian->id])
            ->get(route('setup.index'))
            ->assertOk()
            ->assertSee('B2-T01')
            ->assertDontSee('B1-T01');
    }

    public function test_staff_and_branch_owners_are_scoped_to_their_assigned_branch(): void
    {
        $manaoag = Branch::create(['code' => 'manaoag', 'name' => 'Manaoag']);
        $sanFabian = Branch::create(['code' => 'san-fabian', 'name' => 'San Fabian']);
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $manaoag->id]);
        $staff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $sanFabian->id]);

        $this->createSale($manaoag, 'M-001');
        $this->createSale($sanFabian, 'S-001');

        $this->assertTrue($manager->canAccessBranch($manaoag));
        $this->assertFalse($manager->canAccessBranch($sanFabian));
        $this->assertTrue($staff->canAccessBranch($sanFabian));
        $this->assertSame(
            [$manaoag->id],
            FuelSale::visibleTo($manager)->pluck('branch_id')->unique()->all(),
        );
        $this->assertSame(
            [$sanFabian->id],
            FuelSale::visibleTo($staff)->pluck('branch_id')->unique()->all(),
        );
    }

    public function test_branch_owner_cannot_access_records_or_branches_from_another_location(): void
    {
        $manaoag = Branch::create(['code' => 'manaoag', 'name' => 'Manaoag']);
        $sanFabian = Branch::create(['code' => 'san-fabian', 'name' => 'San Fabian']);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $manaoag->id]);

        $this->createSale($manaoag, 'M-001');
        $this->createSale($sanFabian, 'S-001');

        $this->assertTrue($owner->canAccessBranch($manaoag));
        $this->assertFalse($owner->canAccessBranch($sanFabian));
        $this->assertCount(1, FuelSale::visibleTo($owner)->get());
        $this->assertCount(1, Branch::visibleTo($owner)->get());
    }

    public function test_unassigned_user_cannot_access_branch_records(): void
    {
        $branch = Branch::create(['code' => 'manaoag', 'name' => 'Manaoag']);
        $user = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => null]);
        $this->createSale($branch, 'M-001');

        $this->assertFalse($user->canAccessBranch($branch));
        $this->assertSame(0, FuelSale::visibleTo($user)->count());
        $this->assertSame(0, Branch::visibleTo($user)->count());
    }

    public function test_global_owner_can_access_both_branches_while_staff_remain_scoped(): void
    {
        $manaoag = Branch::create(['code' => 'manaoag', 'name' => 'Manaoag']);
        $sanFabian = Branch::create(['code' => 'san-fabian', 'name' => 'San Fabian']);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => null]);
        $manaoagStaff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $manaoag->id]);
        $sanFabianStaff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $sanFabian->id]);
        $this->createSale($manaoag, 'M-001');
        $this->createSale($sanFabian, 'S-001');

        $this->assertTrue($owner->canAccessBranch($manaoag));
        $this->assertTrue($owner->canAccessBranch($sanFabian));
        $this->assertFalse($manaoagStaff->canAccessBranch($sanFabian));
        $this->assertFalse($sanFabianStaff->canAccessBranch($manaoag));
        $this->assertCount(2, Branch::visibleTo($owner)->get());
        $this->assertCount(2, FuelSale::visibleTo($owner)->get());
        $this->assertSame([$manaoag->id], FuelSale::visibleTo($manaoagStaff)->pluck('branch_id')->unique()->all());
        $this->assertSame([$sanFabian->id], FuelSale::visibleTo($sanFabianStaff)->pluck('branch_id')->unique()->all());

        $this->actingAs($owner)->getJson(route('api.reports.summary', [
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ]))->assertOk()
            ->assertJsonPath('sales.transaction_count', 2);

        $this->actingAs($owner)->getJson(route('api.dashboard'))->assertOk()
            ->assertJsonCount(2, 'inventory');
    }

    public function test_role_migration_promotes_the_form_owner_and_demotes_legacy_branch_administrators(): void
    {
        $manaoag = Branch::create(['code' => 'manaoag', 'name' => 'Manaoag']);
        $sanFabian = Branch::create(['code' => 'san-fabian', 'name' => 'San Fabian']);
        $legacyGlobalOwner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => null]);
        $branchOwner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $manaoag->id]);
        $manager = User::factory()->create(['role' => UserRole::Manager, 'branch_id' => $sanFabian->id]);
        $designatedOwner = User::factory()->create([
            'email' => 'fivecegas8@gmail.com',
            'role' => UserRole::Staff,
            'branch_id' => $sanFabian->id,
        ]);

        $migration = require database_path('migrations/2026_10_30_000002_scope_owners_to_branches_and_merge_managers.php');
        $migration->up();

        $this->assertSame(UserRole::Owner, $designatedOwner->fresh()->role);
        $this->assertNull($designatedOwner->fresh()->branch_id);
        $this->assertSame(UserRole::Staff, $branchOwner->fresh()->role);
        $this->assertFalse($branchOwner->fresh()->is_active);
        $this->assertSame(UserRole::Staff, $manager->fresh()->role);
        $this->assertFalse($manager->fresh()->is_active);
        $this->assertSame(UserRole::Staff, $legacyGlobalOwner->fresh()->role);
        $this->assertFalse($legacyGlobalOwner->fresh()->is_active);
    }

    private function createSale(Branch $branch, string $receiptNumber): FuelSale
    {
        $product = FuelProduct::firstOrCreate(['name' => 'Regular']);
        $tank = FuelTank::create([
            'branch_id' => $branch->id,
            'fuel_product_id' => $product->id,
            'code' => 'T-1',
            'capacity_liters' => 10000,
        ]);
        $pump = FuelPump::create(['branch_id' => $branch->id, 'code' => 'P-1']);
        $nozzle = FuelNozzle::create([
            'branch_id' => $branch->id,
            'fuel_pump_id' => $pump->id,
            'fuel_tank_id' => $tank->id,
            'code' => 'N-1',
        ]);
        $staff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $branch->id]);

        return FuelSale::create([
            'branch_id' => $branch->id,
            'fuel_nozzle_id' => $nozzle->id,
            'fuel_product_id' => $product->id,
            'receipt_number' => $receiptNumber,
            'liters' => 10,
            'price_per_liter' => 60,
            'total_amount' => 600,
            'sold_at' => now(),
            'recorded_by' => $staff->id,
        ]);
    }
}

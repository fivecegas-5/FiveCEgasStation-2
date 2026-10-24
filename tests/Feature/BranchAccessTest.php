<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\FuelNozzle;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelSale;
use App\Models\FuelTank;
use App\Models\User;
use Database\Seeders\BranchSeeder;
use Database\Seeders\FuelProductSeeder;
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
            ['Diesel', 'Premium 95', 'Unleaded 91'],
            FuelProduct::orderBy('name')->pluck('name')->all(),
        );
        $this->assertSame(3, FuelProduct::where('unit', 'liter')->count());
    }

    public function test_staff_and_managers_are_scoped_to_their_assigned_branch(): void
    {
        $manaoag = Branch::create(['code' => 'manaoag', 'name' => 'Manaoag']);
        $sanFabian = Branch::create(['code' => 'san-fabian', 'name' => 'San Fabian']);
        $manager = User::factory()->create(['role' => UserRole::Manager, 'branch_id' => $manaoag->id]);
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

    public function test_owner_can_access_records_and_branches_from_both_locations(): void
    {
        $manaoag = Branch::create(['code' => 'manaoag', 'name' => 'Manaoag']);
        $sanFabian = Branch::create(['code' => 'san-fabian', 'name' => 'San Fabian']);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => null]);

        $this->createSale($manaoag, 'M-001');
        $this->createSale($sanFabian, 'S-001');

        $this->assertTrue($owner->canAccessBranch($manaoag));
        $this->assertTrue($owner->canAccessBranch($sanFabian));
        $this->assertCount(2, FuelSale::visibleTo($owner)->get());
        $this->assertCount(2, Branch::visibleTo($owner)->get());
    }

    public function test_unassigned_user_cannot_access_branch_records(): void
    {
        $branch = Branch::create(['code' => 'manaoag', 'name' => 'Manaoag']);
        $user = User::factory()->create(['branch_id' => null]);
        $this->createSale($branch, 'M-001');

        $this->assertFalse($user->canAccessBranch($branch));
        $this->assertSame(0, FuelSale::visibleTo($user)->count());
        $this->assertSame(0, Branch::visibleTo($user)->count());
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

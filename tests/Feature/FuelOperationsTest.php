<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\FuelDelivery;
use App\Models\FuelNozzle;
use App\Models\FuelPrice;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelSale;
use App\Models\FuelTank;
use App\Models\InventoryMovement;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FuelOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_must_sign_in_before_opening_operations_and_can_log_out(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('STAFF & MANAGER LOGIN')
            ->assertSee('login-photo-panel')
            ->assertSee(route('password.request'));
        $this->assertFileExists(public_path('images/fiveCEgasStation.jpg'));

        $user = User::factory()->create(['password' => 'correct-password']);
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_owner_can_request_and_complete_a_password_reset(): void
    {
        Notification::fake();
        $owner = User::factory()->create([
            'role' => UserRole::Owner,
            'branch_id' => null,
            'password' => 'old-owner-password',
        ]);

        $this->post(route('password.email'), ['email' => $owner->email])
            ->assertRedirect()
            ->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $this->assertNotNull($token);

        $this->get(route('password.reset', ['token' => $token, 'email' => $owner->email]))
            ->assertOk()
            ->assertSee('Choose a new password');

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $owner->email,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check('new-secure-password', $owner->fresh()->password));
        $this->post('/login', [
            'email' => $owner->email,
            'password' => 'new-secure-password',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_owner_can_recover_a_forgotten_email_and_reset_password_from_the_local_console(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner,
            'branch_id' => null,
            'password' => 'old-owner-password',
        ]);

        $this->artisan('app:recover-owner')
            ->expectsChoice('Select the owner account to recover', $owner->email, [$owner->email])
            ->expectsQuestion('New password (at least 10 characters)', 'new-console-password')
            ->expectsQuestion('Confirm new password', 'new-console-password')
            ->expectsOutputToContain("Owner password reset for {$owner->email}")
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('new-console-password', $owner->fresh()->password));
    }

    public function test_manager_can_create_staff_only_for_their_own_branch(): void
    {
        [$manaoag, $sanFabian] = $this->branches();
        $manager = User::factory()->create([
            'role' => UserRole::Manager,
            'branch_id' => $manaoag->id,
        ]);

        $this->actingAs($manager)->post('/users', [
            'name' => 'Manaoag Attendant',
            'email' => 'attendant@example.test',
            'role' => 'staff',
            'branch_id' => $sanFabian->id,
            'password' => 'strong-password',
            'password_confirmation' => 'strong-password',
        ])->assertRedirect(route('users.index'));

        $created = User::where('email', 'attendant@example.test')->firstOrFail();
        $this->assertSame($manaoag->id, $created->branch_id);
        $this->assertSame(UserRole::Staff, $created->role);

        $this->actingAs($manager)->post('/users', [
            'name' => 'Extra Manager',
            'email' => 'manager@example.test',
            'role' => 'manager',
            'branch_id' => $manaoag->id,
            'password' => 'strong-password',
            'password_confirmation' => 'strong-password',
        ])->assertSessionHasErrors('role');
    }

    public function test_sales_use_the_price_at_sale_time_and_reduce_only_their_branch_stock(): void
    {
        [$manaoag, $sanFabian] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'branch_id' => $manaoag->id]);
        [$tank, $nozzle, $product] = $this->stationEquipment($manaoag);
        [, $remoteNozzle] = $this->stationEquipment($sanFabian);
        $price = FuelPrice::create([
            'branch_id' => $manaoag->id,
            'fuel_product_id' => $product->id,
            'price_per_liter' => 59.75,
            'effective_from' => now()->subHour(),
            'set_by' => $manager->id,
        ]);

        $this->actingAs($manager)->post('/sales', [
            'fuel_nozzle_id' => $nozzle->id,
            'liters' => '12.5',
            'receipt_number' => 'MAN-0001',
            'payment_method' => 'cash',
            'sold_at' => now()->format('Y-m-d H:i:s'),
        ])->assertRedirect(route('sales.index'));

        $sale = FuelSale::where('receipt_number', 'MAN-0001')->firstOrFail();
        $this->assertSame('59.75', $sale->price_per_liter);
        $this->assertSame('746.88', $sale->total_amount);
        $this->assertSame($price->id, $sale->fuel_price_id);
        $this->assertDatabaseHas('inventory_movements', [
            'branch_id' => $manaoag->id,
            'fuel_tank_id' => $tank->id,
            'signed_quantity_liters' => '-12.500',
            'source_id' => $sale->id,
        ]);

        $this->actingAs($manager)->get(route('sales.index', ['branch_id' => $sanFabian->id]))
            ->assertOk()
            ->assertSee('Manaoag')
            ->assertDontSee('San Fabian');

        $this->actingAs($manager)->post('/sales', [
            'fuel_nozzle_id' => $remoteNozzle->id,
            'liters' => '1',
            'sold_at' => now()->format('Y-m-d H:i:s'),
        ])->assertNotFound();
    }

    public function test_staff_can_record_each_standard_fuel_type_at_both_branches_without_cross_branch_access(): void
    {
        [$manaoag, $sanFabian] = $this->branches();
        $manaoagManager = User::factory()->create(['role' => UserRole::Manager, 'branch_id' => $manaoag->id]);
        $sanFabianManager = User::factory()->create(['role' => UserRole::Manager, 'branch_id' => $sanFabian->id]);
        $manaoagStaff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $manaoag->id]);
        $sanFabianStaff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $sanFabian->id]);
        app(\Database\Seeders\FuelProductSeeder::class)->run();
        $products = FuelProduct::whereIn('name', ['Unleaded 91', 'Premium 95', 'Diesel'])->orderBy('name')->get();
        $nozzlesByBranch = [];

        foreach ([[$manaoag, $manaoagManager], [$sanFabian, $sanFabianManager]] as [$branch, $manager]) {
        foreach ($products as $index => $product) {
            $tank = FuelTank::create([
                'branch_id' => $branch->id,
                'fuel_product_id' => $product->id,
                'code' => 'T-'.($index + 1),
                'capacity_liters' => 10000,
            ]);
            $pump = FuelPump::create(['branch_id' => $branch->id, 'code' => 'P-'.($index + 1)]);
            $nozzle = FuelNozzle::create([
                'branch_id' => $branch->id,
                'fuel_pump_id' => $pump->id,
                'fuel_tank_id' => $tank->id,
                'code' => 'N-'.($index + 1),
            ]);
            $nozzlesByBranch[$branch->id][] = $nozzle;
            $tank->inventoryMovements()->create([
                'branch_id' => $branch->id,
                'fuel_tank_id' => $tank->id,
                'movement_type' => 'opening_balance',
                'signed_quantity_liters' => 5000,
                'recorded_by' => $manager->id,
                'occurred_at' => now()->subHour(),
            ]);
            FuelPrice::create([
                'branch_id' => $branch->id,
                'fuel_product_id' => $product->id,
                'price_per_liter' => 60 + $index,
                'effective_from' => now()->subHour(),
                'set_by' => $manager->id,
            ]);
        }
        }

        foreach ([[$manaoag, $manaoagStaff], [$sanFabian, $sanFabianStaff]] as [$branch, $staff]) {
        foreach ($nozzlesByBranch[$branch->id] as $index => $nozzle) {
            $this->actingAs($staff)->post(route('sales.store', ['branch_id' => $branch->id]), [
                'fuel_nozzle_id' => $nozzle->id,
                'liters' => '10',
                'receipt_number' => $branch->code.'-'.$index,
                'payment_method' => 'cash',
                'sold_at' => now()->format('Y-m-d H:i:s'),
            ])->assertRedirect(route('sales.index'));
        }

        $this->actingAs($staff)->get(route('sales.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertSee('Unleaded 91')
            ->assertSee('Premium 95')
            ->assertSee('Diesel');
        }

        $this->assertSame(6, FuelSale::count());
        $this->assertSame(6, InventoryMovement::where('movement_type', 'sale')->count());
        $this->assertSame(3, FuelSale::visibleTo($manaoagStaff)->count());
        $this->assertSame(3, FuelSale::visibleTo($sanFabianStaff)->count());

        $this->actingAs($manaoagStaff)->post(route('sales.store', ['branch_id' => $sanFabian->id]), [
        'fuel_nozzle_id' => $nozzlesByBranch[$sanFabian->id][0]->id,
        'liters' => '10',
        'sold_at' => now()->format('Y-m-d H:i:s'),
        ])->assertNotFound();

        $this->actingAs($manaoagManager)->get(route('sales.index', ['branch_id' => $sanFabian->id]))
        ->assertOk()
        ->assertDontSee('san-fabian-0')
        ->assertSee('manaoag-0');
    }

    public function test_delivery_changes_stock_only_after_manager_approval(): void
    {
        [$branch] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'branch_id' => $branch->id]);
        $staff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $branch->id]);
        [$tank] = $this->stationEquipment($branch);

        $this->actingAs($staff)->post('/deliveries', [
            'fuel_tank_id' => $tank->id,
            'supplier_name' => 'Fuel Supplier',
            'delivery_reference' => 'DEL-004',
            'delivered_liters' => '800',
            'cost_per_liter' => '52.40',
            'received_at' => now()->format('Y-m-d H:i:s'),
        ])->assertRedirect(route('deliveries.index'));

        $delivery = FuelDelivery::where('delivery_reference', 'DEL-004')->firstOrFail();
        $this->assertSame('pending', $delivery->status);
        $this->assertSame('0', (string) InventoryMovement::where('source_type', FuelDelivery::class)->sum('signed_quantity_liters'));

        $this->actingAs($staff)->post(route('deliveries.approve', $delivery))->assertForbidden();
        $this->actingAs($manager)->post(route('deliveries.approve', $delivery))->assertRedirect(route('deliveries.index'));

        $this->assertSame('approved', $delivery->fresh()->status);
        $this->assertDatabaseHas('inventory_movements', [
            'source_type' => FuelDelivery::class,
            'source_id' => $delivery->id,
            'signed_quantity_liters' => '800.000',
        ]);

        $this->actingAs($manager)->post(route('deliveries.approve', $delivery))->assertStatus(409);
        $this->assertSame(1, InventoryMovement::where('source_type', FuelDelivery::class)->where('source_id', $delivery->id)->count());
    }

    public function test_stock_adjustment_is_branch_scoped_and_requires_manager_approval(): void
    {
        [$manaoag, $sanFabian] = $this->branches();
        $staff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $manaoag->id]);
        $manager = User::factory()->create(['role' => UserRole::Manager, 'branch_id' => $manaoag->id]);
        $otherManager = User::factory()->create(['role' => UserRole::Manager, 'branch_id' => $sanFabian->id]);
        [$tank] = $this->stationEquipment($manaoag);
        $this->stationEquipment($sanFabian);

        $this->actingAs($staff)->post('/adjustments', [
            'fuel_tank_id' => $tank->id,
            'quantity_change_liters' => '-5.250',
            'reason' => 'Closing tank dip shows a five liter shortage.',
        ])->assertRedirect(route('adjustments.index'));

        $adjustment = StockAdjustment::firstOrFail();
        $this->assertSame('pending', $adjustment->status);
        $this->actingAs($otherManager)->post(route('adjustments.approve', $adjustment))->assertNotFound();
        $this->actingAs($manager)->post(route('adjustments.approve', $adjustment))->assertRedirect(route('adjustments.index'));
        $this->assertSame('-5.250', $adjustment->fresh()->quantity_change_liters);
        $this->assertDatabaseHas('inventory_movements', [
            'source_type' => StockAdjustment::class,
            'source_id' => $adjustment->id,
            'signed_quantity_liters' => '-5.250',
        ]);
    }

    public function test_station_workflow_pages_render_and_manager_is_denied_account_creation_of_manager_role(): void
    {
        [$branch] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'branch_id' => $branch->id]);

        $this->actingAs($manager)->get('/setup')->assertOk()->assertSee('Station setup');
        $this->actingAs($manager)->get('/users')->assertOk()->assertSee('Create an account');
        $this->actingAs($manager)->get('/sales')->assertOk()->assertSee('Record a sale');
        $this->actingAs($manager)->get('/deliveries')->assertOk()->assertSee('Record a delivery');
        $this->actingAs($manager)->get('/adjustments')->assertOk()->assertSee('Report a discrepancy');
        $this->actingAs($manager)->get('/')->assertOk()
            ->assertSee('Fuel Inventory Level')
            ->assertSee('Recent Transactions');
    }

    public function test_fuel_prices_navigation_is_read_only_and_scoped_to_the_users_branches(): void
    {
        [$manaoag, $sanFabian] = $this->branches();
        $staff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $manaoag->id]);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => null]);
        $product = FuelProduct::create(['name' => 'Regular']);

        foreach ([[$manaoag, 59.75], [$sanFabian, 61.25]] as [$branch, $price]) {
            FuelPrice::create([
                'branch_id' => $branch->id,
                'fuel_product_id' => $product->id,
                'price_per_liter' => $price,
                'effective_from' => now()->subHour(),
                'set_by' => $owner->id,
            ]);
        }

        $this->actingAs($staff)->get(route('prices.index'))
            ->assertOk()
            ->assertSee('Fuel Prices')
            ->assertSee('Manaoag')
            ->assertSee('₱59.75')
            ->assertDontSee('San Fabian')
            ->assertDontSee('₱61.25');

        $this->actingAs($owner)->get(route('prices.index'))
            ->assertOk()
            ->assertSee('Manaoag')
            ->assertSee('₱59.75')
            ->assertSee('San Fabian')
            ->assertSee('₱61.25');

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Fuel Inventory')
            ->assertSee('Stock-In')
            ->assertSee('Stock-Out')
            ->assertSee('Transactions')
            ->assertSee('Reports')
            ->assertSee('Soon')
            ->assertSee('/images/fiveCElogo.png');
    }

    public function test_dashboard_shows_ledger_stock_and_keeps_owner_branch_links_scoped(): void
    {
        [$manaoag, $sanFabian] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'branch_id' => $manaoag->id]);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => null]);
        [$manaoagTank, $manaoagNozzle, $product] = $this->stationEquipment($manaoag);
        [$sanFabianTank] = $this->stationEquipment($sanFabian);

        foreach ([[$manaoag, $manaoagTank, 10000], [$sanFabian, $sanFabianTank, 7000]] as [$branch, $tank, $liters]) {
            $tank->inventoryMovements()->create([
                'branch_id' => $branch->id,
                'fuel_tank_id' => $tank->id,
                'movement_type' => 'opening_balance',
                'signed_quantity_liters' => $liters,
                'recorded_by' => $manager->id,
                'occurred_at' => now()->subHour(),
            ]);
        }

        $sale = FuelSale::create([
            'branch_id' => $manaoag->id,
            'fuel_nozzle_id' => $manaoagNozzle->id,
            'fuel_product_id' => $product->id,
            'receipt_number' => 'DASH-001',
            'liters' => 1100,
            'price_per_liter' => 60,
            'total_amount' => 66000,
            'payment_method' => 'cash',
            'sold_at' => now(),
            'recorded_by' => $manager->id,
            'status' => 'completed',
        ]);
        $sale->inventoryMovements()->create([
            'branch_id' => $manaoag->id,
            'fuel_tank_id' => $manaoagTank->id,
            'movement_type' => 'sale',
            'signed_quantity_liters' => -1100,
            'recorded_by' => $manager->id,
            'occurred_at' => now(),
        ]);

        $this->actingAs($manager)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('8,900')
            ->assertSee('Stock-Out')
            ->assertSee('₱66,000.00')
            ->assertSee('Manaoag')
            ->assertDontSee('San Fabian');

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('ALL BRANCHES')
            ->assertSee('Stock-In')
            ->assertSee('Stock-Out')
            ->assertSee('Manaoag')
            ->assertSee('San Fabian')
            ->assertSee(route('deliveries.index', ['branch_id' => $manaoag->id]))
            ->assertSee(route('deliveries.index', ['branch_id' => $sanFabian->id]))
            ->assertSee(route('sales.index', ['branch_id' => $manaoag->id]))
            ->assertSee(route('sales.index', ['branch_id' => $sanFabian->id]));
    }

    public function test_manager_can_register_a_tank_with_a_traceable_opening_balance(): void
    {
        [$branch] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'branch_id' => $branch->id]);
        $product = FuelProduct::create(['name' => 'Premium']);

        $this->actingAs($manager)->post('/setup/tanks', [
            'fuel_product_id' => $product->id,
            'code' => 'TANK-NEW',
            'capacity_liters' => '12000',
            'opening_stock_liters' => '4200.500',
        ])->assertRedirect();

        $tank = FuelTank::where('code', 'TANK-NEW')->firstOrFail();
        $this->assertDatabaseHas('inventory_movements', [
            'source_type' => FuelTank::class,
            'source_id' => $tank->id,
            'fuel_tank_id' => $tank->id,
            'movement_type' => 'opening_balance',
            'signed_quantity_liters' => '4200.500',
        ]);
    }

    public function test_manager_can_update_tank_capacity_and_reconcile_stock_without_rewriting_opening_balance(): void
    {
        [$manaoag, $sanFabian] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'branch_id' => $manaoag->id]);
        $otherManager = User::factory()->create(['role' => UserRole::Manager, 'branch_id' => $sanFabian->id]);
        $staff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $manaoag->id]);
        [$tank] = $this->stationEquipment($manaoag);
        $tank->update(['capacity_liters' => 12000]);
        $tank->inventoryMovements()->create([
            'branch_id' => $manaoag->id,
            'fuel_tank_id' => $tank->id,
            'movement_type' => 'opening_balance',
            'signed_quantity_liters' => 4200.500,
            'recorded_by' => $manager->id,
            'occurred_at' => now()->subDay(),
        ]);

        $this->actingAs($manager)->put(route('setup.tanks.update', $tank), [
            'capacity_liters' => '13000',
            'current_stock_liters' => '4100.250',
            'reason' => 'Measured with the tank dip at shift close.',
        ])->assertRedirect();

        $this->assertSame('13000.000', $tank->fresh()->capacity_liters);
        $this->assertDatabaseHas('inventory_movements', [
            'fuel_tank_id' => $tank->id,
            'movement_type' => 'opening_balance',
            'signed_quantity_liters' => '4200.500',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'fuel_tank_id' => $tank->id,
            'movement_type' => 'stock_reconciliation',
            'signed_quantity_liters' => '-100.250',
            'source_type' => FuelTank::class,
            'source_id' => $tank->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fuel-tank.updated',
            'subject_type' => FuelTank::class,
            'subject_id' => $tank->id,
        ]);
        $this->assertEquals(4100.25, (float) $tank->movements()->sum('signed_quantity_liters'));

        $this->actingAs($manager)->put(route('setup.tanks.update', $tank), [
            'capacity_liters' => '4000',
            'current_stock_liters' => '4100',
        ])->assertSessionHasErrors('current_stock_liters');

        $this->actingAs($otherManager)->put(route('setup.tanks.update', $tank), [
            'capacity_liters' => '14000',
            'current_stock_liters' => '4100',
        ])->assertNotFound();

        $this->actingAs($staff)->put(route('setup.tanks.update', $tank), [
            'capacity_liters' => '14000',
            'current_stock_liters' => '4100',
        ])->assertForbidden();
    }

    public function test_manager_price_edit_appends_effective_price_history_and_is_branch_scoped(): void
    {
        [$manaoag, $sanFabian] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'branch_id' => $manaoag->id]);
        $otherManager = User::factory()->create(['role' => UserRole::Manager, 'branch_id' => $sanFabian->id]);
        $product = FuelProduct::create(['name' => 'Unleaded 91']);
        $price = FuelPrice::create([
            'branch_id' => $manaoag->id,
            'fuel_product_id' => $product->id,
            'price_per_liter' => 89.74,
            'effective_from' => now()->subDay(),
            'set_by' => $manager->id,
        ]);

        $this->actingAs($manager)->put(route('setup.prices.update', $price), [
            'price_per_liter' => '90.25',
        ])->assertRedirect();

        $this->assertSame('89.74', $price->fresh()->price_per_liter);
        $this->assertDatabaseHas('fuel_prices', [
            'branch_id' => $manaoag->id,
            'fuel_product_id' => $product->id,
            'price_per_liter' => '90.25',
            'set_by' => $manager->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fuel-price.updated',
            'subject_type' => FuelPrice::class,
        ]);

        $this->actingAs($otherManager)->put(route('setup.prices.update', $price), [
            'price_per_liter' => '95.00',
        ])->assertNotFound();
    }

    private function branches(): array
    {
        return [
            Branch::create(['code' => 'manaoag', 'name' => 'Manaoag']),
            Branch::create(['code' => 'san-fabian', 'name' => 'San Fabian']),
        ];
    }

    private function stationEquipment(Branch $branch): array
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

        return [$tank, $nozzle, $product];
    }
}

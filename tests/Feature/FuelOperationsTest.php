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
use App\Models\Shift;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Services\InventoryLedger;
use Database\Seeders\FuelProductSeeder;
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
            ->assertSee('data-page="login"', false)
            ->assertSee('data-authenticated="false"', false);
        $this->assertFileExists(public_path('images/fiveCEgasStation.jpg'));

        [$branch] = $this->branches();
        $user = User::factory()->create([
            'role' => UserRole::Owner,
            'branch_id' => $branch->id,
            'password' => 'correct-password',
        ]);
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
        [$branch] = $this->branches();
        $owner = User::factory()->create([
            'role' => UserRole::Owner,
            'branch_id' => $branch->id,
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
            ->assertSee('data-page="reset-password"', false)
            ->assertSee('data-reset-token="'.$token.'"', false);

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
        [$branch] = $this->branches();
        $owner = User::factory()->create([
            'role' => UserRole::Owner,
            'branch_id' => $branch->id,
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

    public function test_branch_owner_can_create_staff_only_for_their_own_branch(): void
    {
        [$manaoag, $sanFabian] = $this->branches();
        $manager = User::factory()->create([
            'role' => UserRole::Owner,
            'branch_id' => $manaoag->id,
        ]);

        $this->actingAs($manager)->post('/users', [
            'name' => 'Manaoag Attendant',
            'email' => 'attendant@example.test',
            'role' => 'staff',
            'branch_id' => $manaoag->id,
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

        $this->actingAs($manager)->post('/users', [
            'name' => 'Cross-branch Staff',
            'email' => 'cross-branch@example.test',
            'role' => 'staff',
            'branch_id' => $sanFabian->id,
            'password' => 'strong-password',
            'password_confirmation' => 'strong-password',
        ])->assertNotFound();
    }

    public function test_global_owner_can_create_staff_for_both_branches_and_select_the_active_branch(): void
    {
        [$manaoag, $sanFabian] = $this->branches();
        $owner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => null]);

        foreach ([
            ['email' => 'manaoag-staff@example.test', 'branch' => $manaoag],
            ['email' => 'sanfabian-staff@example.test', 'branch' => $sanFabian],
        ] as $account) {
            $this->actingAs($owner)->post(route('users.store'), [
                'name' => 'Branch Attendant',
                'email' => $account['email'],
                'role' => UserRole::Staff->value,
                'branch_id' => $account['branch']->id,
                'password' => 'strong-password',
                'password_confirmation' => 'strong-password',
            ])->assertRedirect(route('users.index'));

            $this->assertSame($account['branch']->id, User::where('email', $account['email'])->value('branch_id'));
        }

        $this->actingAs($owner)->get(route('sales.index'))->assertStatus(422);
        $this->actingAs($owner)->post(route('branch-context.update'), ['branch_id' => $sanFabian->id])
            ->assertRedirect();
        $this->assertSame($sanFabian->id, session('active_branch_id'));
        $this->actingAs($owner)->get(route('sales.index'))->assertOk();
    }

    public function test_legacy_manager_account_is_denied_access_after_role_retirement(): void
    {
        [$branch] = $this->branches();
        $manager = User::factory()->create([
            'role' => UserRole::Manager,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);

        $this->actingAs($manager)->get(route('dashboard'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_staff_login_redirects_to_fuel_sale_entry(): void
    {
        [$branch] = $this->branches();
        $staff = User::factory()->create([
            'role' => UserRole::Staff,
            'branch_id' => $branch->id,
        ]);

        $this->post('/login', [
            'email' => $staff->email,
            'password' => 'password',
        ])->assertRedirect(route('sales.index'));

        $this->assertAuthenticatedAs($staff);
        $this->get(route('sales.index'))->assertOk()
            ->assertSee('data-page="sales"', false)
            ->assertSee('data-role="staff"', false);
        $this->get(route('dashboard'))->assertRedirect(route('sales.index'));
        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('sales.import.page'))->assertForbidden();
    }

    public function test_staff_records_liters_against_selected_fuel_and_stock_updates_for_owner(): void
    {
        [$manaoag, $sanFabian] = $this->branches();
        [$tank, $nozzle, $product] = $this->stationEquipment($manaoag);
        [, $otherBranchNozzle] = $this->stationEquipment($sanFabian);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => null]);
        $staff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $manaoag->id]);
        $product = FuelProduct::create(['name' => 'Unleaded 91']);
        $tank->update(['fuel_product_id' => $product->id]);
        app(InventoryLedger::class)->record(
            $tank,
            'opening_balance',
            100,
            $owner,
            $tank,
            now()->subHour(),
        );
        FuelPrice::create([
            'branch_id' => $manaoag->id,
            'fuel_product_id' => $product->id,
            'price_per_liter' => 61.25,
            'effective_from' => now()->subDay(),
            'set_by' => $owner->id,
        ]);

        $this->actingAs($staff)->get(route('sales.index'))->assertOk()
            ->assertSee('data-page="sales"', false);

        $this->actingAs($staff)->postJson(route('sales.store'), [
            'fuel_nozzle_id' => $nozzle->id,
            'liters' => 12,
            'receipt_number' => 'STAFF-001',
            'sold_at' => now()->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('sale.liters', 12)
            ->assertJsonPath('sale.total_amount', 735)
            ->assertJsonPath('sale.receipt_number', 'STAFF-001');

        $this->actingAs($staff)->postJson(route('sales.store'), [
            'fuel_nozzle_id' => $otherBranchNozzle->id,
            'liters' => 1,
            'sold_at' => now()->toIso8601String(),
        ])->assertNotFound();

        $this->assertSame(88.0, (float) $tank->movements()->sum('signed_quantity_liters'));
        $this->assertDatabaseHas('fuel_sales', [
            'branch_id' => $manaoag->id,
            'fuel_nozzle_id' => $nozzle->id,
            'fuel_product_id' => $product->id,
            'recorded_by' => $staff->id,
            'total_amount' => 735,
        ]);

        $this->actingAs($owner)->getJson(route('api.dashboard'))->assertOk()
            ->assertJsonPath('recent_transactions.0.branch', 'Manaoag')
            ->assertJsonPath('recent_transactions.0.liters', 12);
    }

    public function test_sales_use_the_price_at_sale_time_and_reduce_only_their_branch_stock(): void
    {
        [$manaoag, $sanFabian] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $manaoag->id]);
        [$tank, $nozzle, $product] = $this->stationEquipment($manaoag);
        [, $remoteNozzle] = $this->stationEquipment($sanFabian);
        $tank->inventoryMovements()->create([
            'branch_id' => $manaoag->id,
            'fuel_tank_id' => $tank->id,
            'movement_type' => 'opening_balance',
            'signed_quantity_liters' => 100,
            'recorded_by' => $manager->id,
            'occurred_at' => now()->subHour(),
        ]);
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
        $this->actingAs($manager)->post('/sales', [
            'fuel_nozzle_id' => $nozzle->id,
            'liters' => '1',
            'receipt_number' => 'MAN-0001',
            'payment_method' => 'cash',
            'sold_at' => now()->format('Y-m-d H:i:s'),
        ])->assertSessionHasErrors('receipt_number');
        $this->assertSame(1, FuelSale::where('receipt_number', 'MAN-0001')->count());

        $this->actingAs($manager)->get(route('sales.index', ['branch_id' => $sanFabian->id]))
            ->assertNotFound();

        $this->actingAs($manager)->post('/sales', [
            'fuel_nozzle_id' => $remoteNozzle->id,
            'liters' => '1',
            'sold_at' => now()->format('Y-m-d H:i:s'),
        ])->assertNotFound();
    }

    public function test_managers_can_record_each_standard_fuel_type_only_in_their_branch(): void
    {
        [$manaoag, $sanFabian] = $this->branches();
        $manaoagManager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $manaoag->id]);
        $sanFabianManager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $sanFabian->id]);
        $manaoagStaff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $manaoag->id]);
        $sanFabianStaff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $sanFabian->id]);
        app(FuelProductSeeder::class)->run();
        $products = FuelProduct::whereIn('name', ['Unleaded', 'Premium', 'Diesel'])->orderBy('name')->get();
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

        foreach ([[$manaoag, $manaoagManager], [$sanFabian, $sanFabianManager]] as [$branch, $manager]) {
            foreach ($nozzlesByBranch[$branch->id] as $index => $nozzle) {
                $this->actingAs($manager)->post(route('sales.store', ['branch_id' => $branch->id]), [
                    'fuel_nozzle_id' => $nozzle->id,
                    'liters' => '10',
                    'receipt_number' => $branch->code.'-'.$index,
                    'payment_method' => 'cash',
                    'sold_at' => now()->format('Y-m-d H:i:s'),
                ])->assertRedirect(route('sales.index'));
            }

            $this->actingAs($manager)->get(route('sales.index', ['branch_id' => $branch->id]))
                ->assertOk()
                ->assertSee('data-page="sales"', false);
        }

        $this->assertSame(6, FuelSale::count());
        $this->assertSame(6, InventoryMovement::where('movement_type', 'sale')->count());
        $this->assertSame(3, FuelSale::visibleTo($manaoagStaff)->count());
        $this->assertSame(3, FuelSale::visibleTo($sanFabianStaff)->count());

        $this->actingAs($manaoagManager)->post(route('sales.store', ['branch_id' => $sanFabian->id]), [
            'fuel_nozzle_id' => $nozzlesByBranch[$sanFabian->id][0]->id,
            'liters' => '10',
            'sold_at' => now()->format('Y-m-d H:i:s'),
        ])->assertNotFound();

        $this->actingAs($manaoagManager)->get(route('sales.index', ['branch_id' => $sanFabian->id]))
            ->assertNotFound();
    }

    public function test_delivery_changes_stock_only_after_manager_approval(): void
    {
        [$branch] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
        $staff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $branch->id]);
        [$tank] = $this->stationEquipment($branch);

        $this->actingAs($manager)->post('/deliveries', [
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

    public function test_stock_adjustment_is_branch_scoped_and_owner_approval_is_audited(): void
    {
        [$manaoag, $sanFabian] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $manaoag->id]);
        $reviewer = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $manaoag->id]);
        $otherManager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $sanFabian->id]);
        [$tank] = $this->stationEquipment($manaoag);
        $tank->inventoryMovements()->create([
            'branch_id' => $manaoag->id,
            'fuel_tank_id' => $tank->id,
            'movement_type' => 'opening_balance',
            'signed_quantity_liters' => 100,
            'recorded_by' => $manager->id,
            'occurred_at' => now()->subHour(),
        ]);
        $this->stationEquipment($sanFabian);

        $this->actingAs($manager)->post('/adjustments', [
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

    public function test_station_workflow_pages_render_for_branch_owner_and_manager_role_is_rejected(): void
    {
        [$branch] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);

        $this->actingAs($manager)->get('/setup')->assertOk()->assertSee('data-page="setup"', false);
        $this->actingAs($manager)->get('/users')->assertOk()->assertSee('data-page="users"', false);
        $this->actingAs($manager)->get('/sales')->assertOk()->assertSee('data-page="sales"', false);
        $this->actingAs($manager)->get('/deliveries')->assertOk()->assertSee('data-page="deliveries"', false);
        $this->actingAs($manager)->get('/adjustments')->assertOk()->assertSee('data-page="adjustments"', false);
        $this->actingAs($manager)->get('/inventory')->assertOk()->assertSee('data-page="inventory"', false);
        $this->actingAs($manager)->get('/transactions')->assertOk()->assertSee('data-page="transactions"', false);
        $this->actingAs($manager)->get('/reports')->assertOk()->assertSee('data-page="reports"', false);
        $this->actingAs($manager)->get('/')->assertOk()
            ->assertSee('data-page="dashboard"', false);
    }

    public function test_fuel_prices_navigation_is_read_only_and_branch_scoped(): void
    {
        [$manaoag, $sanFabian] = $this->branches();
        $staff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $manaoag->id]);
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $manaoag->id]);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $sanFabian->id]);
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

        $this->actingAs($manager)->get(route('prices.index'))
            ->assertOk()
            ->assertSee('data-page="prices"', false)
            ->assertSee('data-branch-name="Manaoag"', false)
            ->assertDontSee('data-branch-name="San Fabian"', false);

        $this->actingAs($owner)->get(route('prices.index'))
            ->assertOk()
            ->assertSee('data-page="prices"', false)
            ->assertSee('data-branch-name="San Fabian"', false);
        $this->actingAs($owner)->get(route('prices.index', ['branch_id' => $manaoag->id]))
            ->assertNotFound();

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertRedirect(route('sales.index'));
        $this->actingAs($staff)->get(route('prices.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-page="dashboard"', false);
    }

    public function test_dashboard_shows_only_the_owner_assigned_branch(): void
    {
        [$manaoag, $sanFabian] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $manaoag->id]);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $sanFabian->id]);
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
            'price_per_liter' => 80,
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
            ->assertSee('data-page="dashboard"', false)
            ->assertSee('data-branch-name="Manaoag"', false);

        $this->actingAs($manager)->getJson(route('api.dashboard'))
            ->assertOk()
            ->assertJsonPath('user.branch', 'Manaoag')
            ->assertJsonPath('inventory.0.stock_liters', 8900)
            ->assertJsonPath('recent_transactions.0.type', 'Stock-Out')
            ->assertJsonPath('recent_transactions.0.amount', 66000)
            ->assertDontSee('San Fabian');

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-page="dashboard"', false)
            ->assertSee('data-branch-name="San Fabian"', false);

        $this->actingAs($owner)->getJson(route('api.dashboard'))
            ->assertOk()
            ->assertJsonPath('user.branch', 'San Fabian')
            ->assertDontSee('Manaoag');
    }

    public function test_manager_can_register_a_tank_with_a_traceable_opening_balance(): void
    {
        [$branch] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
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
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $manaoag->id]);
        $otherManager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $sanFabian->id]);
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
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $manaoag->id]);
        $otherManager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $sanFabian->id]);
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

    public function test_sale_cannot_exceed_available_stock(): void
    {
        [$branch] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
        [$tank, $nozzle, $product] = $this->stationEquipment($branch);
        $tank->inventoryMovements()->create([
            'branch_id' => $branch->id,
            'fuel_tank_id' => $tank->id,
            'movement_type' => 'opening_balance',
            'signed_quantity_liters' => 5,
            'recorded_by' => $manager->id,
            'occurred_at' => now()->subHour(),
        ]);
        FuelPrice::create([
            'branch_id' => $branch->id,
            'fuel_product_id' => $product->id,
            'price_per_liter' => 60,
            'effective_from' => now()->subHour(),
            'set_by' => $manager->id,
        ]);

        $this->actingAs($manager)->post(route('sales.store'), [
            'fuel_nozzle_id' => $nozzle->id,
            'liters' => '6',
            'sold_at' => now()->format('Y-m-d H:i:s'),
        ])->assertSessionHasErrors('liters');

        $this->assertSame(0, FuelSale::count());
        $this->assertSame(5.0, (float) $tank->movements()->sum('signed_quantity_liters'));
    }

    public function test_shift_readings_capture_nozzle_and_tank_variances_and_weighted_average_profit(): void
    {
        [$branch] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
        [$tank, $nozzle, $product] = $this->stationEquipment($branch);
        $tank->inventoryMovements()->create([
            'branch_id' => $branch->id,
            'fuel_tank_id' => $tank->id,
            'movement_type' => 'opening_balance',
            'signed_quantity_liters' => 100,
            'unit_cost_per_liter' => 50,
            'recorded_by' => $manager->id,
            'occurred_at' => now()->subHour(),
        ]);
        $delivery = FuelDelivery::create([
            'branch_id' => $branch->id,
            'fuel_tank_id' => $tank->id,
            'supplier_name' => 'Verified supplier',
            'delivery_reference' => 'COST-001',
            'delivered_liters' => 100,
            'cost_per_liter' => 70,
            'total_cost' => 7000,
            'received_at' => now()->subMinutes(30),
            'recorded_by' => $manager->id,
            'status' => 'approved',
            'reviewed_by' => $manager->id,
            'reviewed_at' => now()->subMinutes(30),
        ]);
        app(InventoryLedger::class)->record(
            $tank,
            'delivery',
            100,
            $manager,
            $delivery,
            $delivery->received_at,
            70,
        );
        FuelPrice::create([
            'branch_id' => $branch->id,
            'fuel_product_id' => $product->id,
            'price_per_liter' => 80,
            'effective_from' => now()->subHour(),
            'set_by' => $manager->id,
        ]);

        $this->actingAs($manager)->postJson(route('api.shifts.open'), [
            'opening_tank_readings' => [[
                'fuel_tank_id' => $tank->id,
                'measured_liters' => 200,
                'dip_cm' => 10,
            ]],
            'opening_nozzle_readings' => [[
                'fuel_nozzle_id' => $nozzle->id,
                'meter_reading' => 500,
            ]],
        ])->assertCreated();

        $shift = Shift::firstOrFail();
        $sanFabian = Branch::where('code', 'san-fabian')->firstOrFail();
        $remoteManager = User::factory()->create([
            'role' => UserRole::Owner,
            'branch_id' => $sanFabian->id,
        ]);
        $this->actingAs($remoteManager)->postJson(route('api.shifts.close', $shift), [])
            ->assertNotFound();

        $this->actingAs($manager)->postJson(route('api.shifts.open'), [
            'opening_tank_readings' => [[
                'fuel_tank_id' => $tank->id,
                'measured_liters' => 200,
            ]],
            'opening_nozzle_readings' => [[
                'fuel_nozzle_id' => $nozzle->id,
                'meter_reading' => 500,
            ]],
        ])->assertStatus(409);

        $this->actingAs($manager)->post(route('sales.store'), [
            'fuel_nozzle_id' => $nozzle->id,
            'liters' => '10',
            'receipt_number' => 'SHIFT-001',
            'sold_at' => now()->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $this->assertSame($shift->id, FuelSale::where('receipt_number', 'SHIFT-001')->firstOrFail()->shift_id);

        $this->actingAs($manager)->postJson(route('api.shifts.close', $shift), [
            'closing_tank_readings' => [[
                'fuel_tank_id' => $tank->id,
                'measured_liters' => 189,
                'dip_cm' => 9,
            ]],
            'closing_nozzle_readings' => [[
                'fuel_nozzle_id' => $nozzle->id,
                'meter_reading' => 510.5,
            ]],
        ])->assertOk()
            ->assertJsonPath('data.closing_summary.tanks.'.$tank->id.'.variance_liters', -1)
            ->assertJsonPath('data.closing_summary.nozzles.'.$nozzle->id.'.variance_liters', 0.5);

        $this->actingAs($manager)->getJson(route('api.reports.summary', [
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ]))->assertOk()
            ->assertJsonPath('sales.cost_coverage_percent', 100)
            ->assertJsonPath('sales.cost_of_goods_sold', 600)
            ->assertJsonPath('sales.estimated_gross_profit', 200)
            ->assertJsonPath('sales.profit_is_partial', false)
            ->assertJsonPath('closed_shifts.0.closing_summary.nozzles.'.$nozzle->id.'.variance_liters', 0.5);
        $this->actingAs($manager)->getJson(route('api.reports.summary', [
            'branch_id' => $sanFabian->id,
        ]))->assertNotFound();

        $csvResponse = $this->actingAs($manager)->get(route('api.reports.sales.csv', [
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ]))->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertStreamed();
        $this->assertStringContainsString('SHIFT-001', $csvResponse->streamedContent());
    }

    public function test_inactive_accounts_cannot_sign_in_and_managers_can_deactivate_only_branch_staff(): void
    {
        [$branch, $otherBranch] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
        $staff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $branch->id]);
        $remoteStaff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $otherBranch->id]);

        $this->actingAs($manager)->patch(route('users.status', $staff), ['is_active' => false])
            ->assertRedirect(route('users.index'));
        $this->assertFalse($staff->fresh()->is_active);
        $this->post('/logout')->assertRedirect(route('login'));
        $this->post('/login', ['email' => $staff->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->actingAs($manager)->patch(route('users.status', $remoteStaff), ['is_active' => false])
            ->assertNotFound();
        $this->assertTrue($remoteStaff->fresh()->is_active);
    }

    public function test_delivery_approval_cannot_put_stock_over_capacity(): void
    {
        [$branch] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
        [$tank] = $this->stationEquipment($branch);
        $tank->update(['capacity_liters' => 100]);

        $this->actingAs($manager)->post(route('deliveries.store'), [
            'fuel_tank_id' => $tank->id,
            'delivered_liters' => 101,
            'cost_per_liter' => 50,
            'received_at' => now()->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $delivery = FuelDelivery::firstOrFail();

        $this->actingAs($manager)->post(route('deliveries.approve', $delivery))
            ->assertSessionHasErrors('delivered_liters');

        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame(0, InventoryMovement::where('source_type', FuelDelivery::class)->count());
    }

    public function test_owner_can_disable_unreferenced_legacy_fuel_name_without_deleting_it(): void
    {
        $branch = Branch::create(['code' => 'manaoag', 'name' => 'Manaoag']);
        $owner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
        $legacy = FuelProduct::create(['name' => 'Unleaded']);
        $tankProduct = FuelProduct::create(['name' => 'Legacy tank fuel']);
        FuelTank::create([
            'branch_id' => $branch->id,
            'fuel_product_id' => $tankProduct->id,
            'code' => 'T-1',
            'capacity_liters' => 5000,
        ]);

        $this->actingAs($owner)->patch(route('setup.products.status', $legacy), ['is_active' => false])
            ->assertRedirect();
        $this->assertFalse($legacy->fresh()->is_active);
        $this->assertDatabaseHas('fuel_products', ['id' => $legacy->id, 'name' => 'Unleaded']);

        $this->actingAs($owner)->patch(route('setup.products.status', $tankProduct), ['is_active' => false])
            ->assertSessionHasErrors('product');
        $this->assertTrue($tankProduct->fresh()->is_active);
    }

    public function test_manager_can_reject_delivery_and_adjustment_without_moving_stock(): void
    {
        [$branch] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
        $reviewer = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
        [$tank] = $this->stationEquipment($branch);

        $this->actingAs($manager)->post(route('deliveries.store'), [
            'fuel_tank_id' => $tank->id,
            'delivered_liters' => 100,
            'cost_per_liter' => 50,
            'received_at' => now()->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $delivery = FuelDelivery::firstOrFail();
        $this->actingAs($reviewer)->post(route('deliveries.reject', $delivery), [
            'review_notes' => 'Delivery reference could not be verified.',
        ])->assertRedirect();
        $this->assertSame('rejected', $delivery->fresh()->status);
        $this->assertSame(0, InventoryMovement::where('source_type', FuelDelivery::class)->count());

        $tank->inventoryMovements()->create([
            'branch_id' => $branch->id,
            'fuel_tank_id' => $tank->id,
            'movement_type' => 'opening_balance',
            'signed_quantity_liters' => 100,
            'recorded_by' => $manager->id,
            'occurred_at' => now()->subHour(),
        ]);
        $this->actingAs($manager)->post(route('adjustments.store'), [
            'fuel_tank_id' => $tank->id,
            'quantity_change_liters' => -5,
            'reason' => 'Physical variance report.',
        ])->assertRedirect();
        $adjustment = StockAdjustment::firstOrFail();
        $this->actingAs($reviewer)->post(route('adjustments.reject', $adjustment), [
            'review_notes' => 'Recount required before adjustment.',
        ])->assertRedirect();
        $this->assertSame('rejected', $adjustment->fresh()->status);
        $this->assertSame(100.0, (float) $tank->movements()->sum('signed_quantity_liters'));
    }

    public function test_manager_can_void_a_recorded_sale_with_audited_stock_reversal(): void
    {
        [$branch] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
        $recorder = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
        [$tank, $nozzle, $product] = $this->stationEquipment($branch);
        $tank->inventoryMovements()->create([
            'branch_id' => $branch->id,
            'fuel_tank_id' => $tank->id,
            'movement_type' => 'opening_balance',
            'signed_quantity_liters' => 100,
            'unit_cost_per_liter' => 50,
            'recorded_by' => $manager->id,
            'occurred_at' => now()->subHour(),
        ]);
        FuelPrice::create([
            'branch_id' => $branch->id,
            'fuel_product_id' => $product->id,
            'price_per_liter' => 60,
            'effective_from' => now()->subHour(),
            'set_by' => $manager->id,
        ]);
        $this->actingAs($recorder)->post(route('sales.store'), [
            'fuel_nozzle_id' => $nozzle->id,
            'liters' => 10,
            'receipt_number' => 'VOID-001',
            'sold_at' => now()->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $sale = FuelSale::where('receipt_number', 'VOID-001')->firstOrFail();

        $this->actingAs($manager)->post(route('sales.void', $sale), [
            'void_reason' => 'Duplicate recording; verified against receipt.',
        ])->assertRedirect();
        $this->assertSame('voided', $sale->fresh()->status);
        $this->assertSame(100.0, (float) $tank->movements()->sum('signed_quantity_liters'));
        $this->assertDatabaseHas('inventory_movements', [
            'source_type' => FuelSale::class,
            'source_id' => $sale->id,
            'movement_type' => 'sale_void',
            'signed_quantity_liters' => '10.000',
            'unit_cost_per_liter' => '50.00',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fuel-sale.voided',
            'subject_type' => FuelSale::class,
            'subject_id' => $sale->id,
        ]);
        $this->actingAs($manager)->post(route('sales.void', $sale), [
            'void_reason' => 'Attempt duplicate void.',
        ])->assertStatus(409);
        $this->assertSame(0, FuelSale::where('status', 'completed')->count());
        $this->actingAs($manager)->getJson(route('api.reports.summary', [
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ]))->assertOk()
            ->assertJsonPath('sales.transaction_count', 0)
            ->assertJsonPath('sales.revenue', 0);
    }

    public function test_owner_can_edit_a_manual_sale_with_an_audited_stock_correction(): void
    {
        [$branch] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
        $recorder = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
        [$tank, $nozzle, $product] = $this->stationEquipment($branch);
        $tank->inventoryMovements()->create([
            'branch_id' => $branch->id,
            'fuel_tank_id' => $tank->id,
            'movement_type' => 'opening_balance',
            'signed_quantity_liters' => 100,
            'unit_cost_per_liter' => 50,
            'recorded_by' => $manager->id,
            'occurred_at' => now()->subHour(),
        ]);
        FuelPrice::create([
            'branch_id' => $branch->id,
            'fuel_product_id' => $product->id,
            'price_per_liter' => 60,
            'effective_from' => now()->subHour(),
            'set_by' => $manager->id,
        ]);

        $this->actingAs($recorder)->post(route('sales.store'), [
            'fuel_nozzle_id' => $nozzle->id,
            'liters' => 10,
            'receipt_number' => 'EDIT-001',
            'sold_at' => now()->subMinute()->toDateTimeString(),
        ])->assertRedirect();
        $sale = FuelSale::where('receipt_number', 'EDIT-001')->firstOrFail();

        $this->actingAs($manager)->put(route('sales.update', $sale), [
            'liters' => 8,
            'receipt_number' => 'EDIT-002',
            'payment_method' => 'cash',
            'edit_reason' => 'Corrected from the signed POS receipt.',
        ])->assertRedirect();

        $this->assertSame('8.000', $sale->fresh()->liters);
        $this->assertSame('480.00', $sale->fresh()->total_amount);
        $this->assertEquals(92, (float) $tank->movements()->sum('signed_quantity_liters'));
        $this->assertDatabaseHas('inventory_movements', [
            'fuel_tank_id' => $tank->id,
            'movement_type' => 'sale_edit',
            'signed_quantity_liters' => '2.000',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fuel-sale.edited',
            'subject_id' => $sale->id,
        ]);
        $this->actingAs($manager)->getJson(route('api.reports.summary', [
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]))->assertOk()
            ->assertJsonPath('sales.cost_of_goods_sold', 400)
            ->assertJsonPath('sales.estimated_gross_profit', 80);
    }

    public function test_equipment_lifecycle_is_branch_scoped_and_locked_during_open_shifts(): void
    {
        [$branch, $otherBranch] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
        $otherManager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $otherBranch->id]);
        [$tank, $nozzle] = $this->stationEquipment($branch);
        [$remoteTank] = $this->stationEquipment($otherBranch);

        $shift = Shift::create([
            'branch_id' => $branch->id,
            'opened_by' => $manager->id,
            'opened_at' => now(),
            'status' => 'open',
        ]);
        $this->actingAs($manager)->patch(route('setup.nozzles.status', $nozzle), ['is_active' => false])
            ->assertStatus(409);
        $shift->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $manager->id]);

        $this->actingAs($otherManager)->patch(route('setup.tanks.status', $tank), ['is_active' => false])
            ->assertNotFound();
        $this->actingAs($manager)->patch(route('setup.tanks.status', $tank), ['is_active' => false])
            ->assertStatus(422);
        $this->actingAs($manager)->patch(route('setup.nozzles.status', $nozzle), ['is_active' => false])
            ->assertRedirect();
        $this->actingAs($manager)->patch(route('setup.pumps.status', $nozzle->pump), ['is_active' => false])
            ->assertRedirect();
        $this->actingAs($manager)->patch(route('setup.tanks.status', $tank), ['is_active' => false])
            ->assertRedirect();
        $this->assertFalse($tank->fresh()->is_active);
        $this->assertTrue($remoteTank->fresh()->is_active);
    }

    public function test_branch_owner_can_edit_staff_but_cannot_manage_owners_or_other_branches(): void
    {
        [$branch, $otherBranch] = $this->branches();
        $owner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
        $staff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $branch->id]);
        $otherOwner = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $otherBranch->id]);

        $this->actingAs($owner)->put(route('users.update', $staff), [
            'name' => 'Updated Staff',
            'email' => 'updated-staff@example.test',
            'role' => 'staff',
            'branch_id' => $branch->id,
        ])->assertRedirect(route('users.index'));
        $this->assertSame('Updated Staff', $staff->fresh()->name);
        $this->assertSame('updated-staff@example.test', $staff->fresh()->email);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.updated',
            'subject_type' => User::class,
            'subject_id' => $staff->id,
        ]);

        $this->actingAs($owner)->put(route('users.update', $otherOwner), [
            'name' => 'Cross-branch Owner',
            'role' => 'owner',
            'branch_id' => $branch->id,
        ])->assertNotFound();
        $this->assertSame(UserRole::Owner, $owner->fresh()->role);
    }

    public function test_manager_can_edit_station_equipment_without_rewriting_history_or_crossing_branches(): void
    {
        [$branch, $otherBranch] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
        $otherManager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $otherBranch->id]);
        [$tank, $nozzle] = $this->stationEquipment($branch);
        [$otherTank, $otherNozzle] = $this->stationEquipment($otherBranch);
        $secondTank = FuelTank::create([
            'branch_id' => $branch->id,
            'fuel_product_id' => $tank->fuel_product_id,
            'code' => 'T-2',
            'capacity_liters' => 5000,
        ]);
        $secondPump = FuelPump::create(['branch_id' => $branch->id, 'code' => 'P-2']);

        $this->actingAs($manager)->put(route('setup.tanks.update', $tank), [
            'code' => 'T-1-RENAMED',
            'capacity_liters' => 11000,
            'current_stock_liters' => 0,
        ])->assertRedirect();
        $this->actingAs($manager)->put(route('setup.pumps.update', $nozzle->pump), [
            'code' => 'P-1-RENAMED',
        ])->assertRedirect();
        $this->actingAs($manager)->put(route('setup.nozzles.update', $nozzle), [
            'code' => 'N-2',
            'fuel_pump_id' => $secondPump->id,
            'fuel_tank_id' => $secondTank->id,
        ])->assertRedirect();

        $this->assertSame('T-1-RENAMED', $tank->fresh()->code);
        $this->assertSame('P-1-RENAMED', $nozzle->pump->fresh()->code);
        $this->assertSame($secondTank->id, $nozzle->fresh()->fuel_tank_id);
        $this->actingAs($otherManager)->put(route('setup.nozzles.update', $otherNozzle), [
            'fuel_tank_id' => $tank->id,
        ])->assertNotFound();

        FuelSale::create([
            'branch_id' => $branch->id,
            'fuel_nozzle_id' => $nozzle->id,
            'fuel_product_id' => $tank->fuel_product_id,
            'receipt_number' => 'LOCK-NOZZLE',
            'liters' => 1,
            'price_per_liter' => 50,
            'total_amount' => 50,
            'sold_at' => now(),
            'recorded_by' => $manager->id,
        ]);
        $this->actingAs($manager)->put(route('setup.nozzles.update', $nozzle), [
            'fuel_tank_id' => $tank->id,
        ])->assertStatus(422);
        $this->assertSame($secondTank->id, $nozzle->fresh()->fuel_tank_id);
    }

    public function test_report_date_window_is_bounded_and_invalid_ranges_are_rejected(): void
    {
        [$branch] = $this->branches();
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);

        $this->actingAs($manager)->getJson(route('api.reports.summary', [
            'from' => '2024-01-01',
            'to' => '2026-10-27',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->actingAs($manager)->getJson(route('api.reports.sales', [
            'from' => '2026-10-28',
            'to' => '2026-10-27',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('to');
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

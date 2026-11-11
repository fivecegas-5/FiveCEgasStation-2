<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FuelCreditAgreement;
use App\Models\FuelCreditCustomer;
use App\Models\FuelCreditInstallment;
use App\Models\FuelCreditPayment;
use App\Models\FuelDelivery;
use App\Models\FuelNozzle;
use App\Models\FuelPrice;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelSale;
use App\Models\FuelTank;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CreditAndSalesImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_customers_support_email_and_branch_scoped_csv_import_export(): void
    {
        [$branch, $manager] = $this->station();
        $this->actingAs($manager)->postJson(route('api.credit.customers.store'), [
            'name' => 'Email Customer',
            'email' => 'customer@example.test',
            'phone' => '09170000000',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'customer@example.test');

        $this->postJson(route('api.credit.customers.import'), [
            'file' => UploadedFile::fake()->createWithContent(
                'customers.csv',
                "Name,Email,Phone\nImported Customer,imported@example.test,09171111111\n",
            ),
        ])->assertCreated()
            ->assertJsonPath('imported', 1);

        $this->assertDatabaseHas('fuel_credit_customers', [
            'branch_id' => $branch->id,
            'name' => 'Imported Customer',
            'email' => 'imported@example.test',
            'phone' => '09171111111',
        ]);

        $export = $this->get(route('api.credit.customers.export'))->assertOk();
        $this->assertStringContainsString('Name,Email,Phone', $export->streamedContent());
        $this->assertStringContainsString('customer@example.test', $export->streamedContent());
        $this->assertStringContainsString('imported@example.test', $export->streamedContent());

        $this->postJson(route('api.credit.customers.import'), [
            'file' => UploadedFile::fake()->createWithContent(
                'duplicate.csv',
                "Name,Email,Phone\nDuplicate Customer,imported@example.test,09172222222\n",
            ),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('row_2');
        $this->assertDatabaseMissing('fuel_credit_customers', [
            'branch_id' => $branch->id,
            'name' => 'Duplicate Customer',
        ]);
    }

    public function test_manager_can_issue_fuel_credit_record_monthly_dues_and_allocate_partial_payments(): void
    {
        Storage::fake('local');
        [$branch, $manager, $tank, $nozzle, $product] = $this->station();
        $customer = $this->actingAs($manager)->postJson(route('api.credit.customers.store'), [
            'name' => 'Maria Customer',
            'phone' => '09170000000',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Maria Customer')
            ->json('data');

        $firstDueDate = today()->addMonthNoOverflow()->toDateString();
        $response = $this->postJson(route('api.credit.agreements.store'), [
            'fuel_credit_customer_id' => $customer['id'],
            'fuel_nozzle_id' => $nozzle->id,
            'liters' => '10',
            'monthly_payment_amount' => '200',
            'first_due_date' => $firstDueDate,
            'agreement_reference' => 'AGR-100',
            'agreement' => UploadedFile::fake()->create('agreement.pdf', 20, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('data.customer.name', 'Maria Customer')
            ->assertJsonPath('data.value_borrowed', 597.5)
            ->assertJsonPath('data.balance', 597.5)
            ->assertJsonPath('data.pending_payment_amount', 200)
            ->assertJsonPath('data.installments.2.amount_due', 197.5);

        $agreement = FuelCreditAgreement::firstOrFail();
        $this->assertSame(90.0, (float) $tank->movements()->sum('signed_quantity_liters'));
        $this->assertSame('credit', $agreement->sale->payment_method);
        $this->assertSame(3, FuelCreditInstallment::where('fuel_credit_agreement_id', $agreement->id)->count());
        $this->assertNotNull($agreement->agreement_file_path);
        Storage::disk('local')->assertExists($agreement->agreement_file_path);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fuel-credit.agreement.created',
            'subject_id' => $agreement->id,
        ]);

        $this->postJson(route('api.credit.payments.store', $agreement), [
            'amount' => '250',
            'paid_at' => now()->toIso8601String(),
            'payment_method' => 'cash',
            'reference' => 'PAY-1',
        ])->assertCreated();

        $agreement->refresh();
        $this->assertSame('active', $agreement->status);
        $this->assertSame('200.00', $agreement->installments()->where('installment_number', 1)->value('amount_paid'));
        $this->assertSame('50.00', $agreement->installments()->where('installment_number', 2)->value('amount_paid'));
        $this->assertSame('347.50', number_format((float) $agreement->principal_amount - (float) $agreement->payments()->sum('amount'), 2, '.', ''));

        $this->getJson(route('api.credit.agreements.index'))
            ->assertOk()
            ->assertJsonPath('data.data.0.balance', 347.5)
            ->assertJsonPath('data.data.0.pending_payment_amount', 150)
            ->assertJsonPath('data.data.0.installments.0.due_date', $firstDueDate)
            ->assertJsonPath('data.data.0.installments.1.balance', 150)
            ->assertJsonPath('data.data.0.installments.2.amount_due', 197.5);
        $this->get(route('api.credit.agreements.document', $agreement))
            ->assertOk();

        $this->postJson(route('api.credit.payments.store', $agreement), [
            'amount' => '347.51',
            'paid_at' => now()->toIso8601String(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->postJson(route('api.credit.payments.store', $agreement), [
            'amount' => '347.50',
            'paid_at' => now()->toIso8601String(),
        ])->assertCreated();
        $this->assertSame('paid', $agreement->fresh()->status);
        $this->assertSame(2, FuelCreditPayment::where('fuel_credit_agreement_id', $agreement->id)->count());
    }

    public function test_credit_records_and_private_documents_are_branch_scoped(): void
    {
        Storage::fake('local');
        [$branch, $manager, $tank, $nozzle] = $this->station();
        $customer = FuelCreditCustomer::create([
            'branch_id' => $branch->id,
            'name' => 'Branch Customer',
            'created_by' => $manager->id,
        ]);
        $agreement = $this->actingAs($manager)->postJson(route('api.credit.agreements.store'), [
            'fuel_credit_customer_id' => $customer->id,
            'fuel_nozzle_id' => $nozzle->id,
            'liters' => 1,
            'monthly_payment_amount' => 1,
            'first_due_date' => today()->addMonthNoOverflow()->toDateString(),
            'agreement' => UploadedFile::fake()->create('agreement.pdf', 5, 'application/pdf'),
        ])->assertCreated();
        $agreementId = $agreement->json('data.id');

        $remote = Branch::create(['code' => 'remote', 'name' => 'Remote']);
        $remoteManager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $remote->id]);
        $this->actingAs($remoteManager)->getJson(route('api.credit.agreements.show', $agreementId))
            ->assertNotFound();
        $this->get(route('api.credit.agreements.document', $agreementId))->assertNotFound();
        $this->actingAs($remoteManager)->getJson(route('api.credit.agreements.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
    }

    public function test_manager_can_reverse_a_credit_payment_without_erasing_its_history(): void
    {
        [$branch, $manager, $tank, $nozzle] = $this->station();
        $customer = FuelCreditCustomer::create([
            'branch_id' => $branch->id,
            'name' => 'Payment Reversal Customer',
            'created_by' => $manager->id,
        ]);
        $agreementResponse = $this->actingAs($manager)->postJson(route('api.credit.agreements.store'), [
            'fuel_credit_customer_id' => $customer->id,
            'fuel_nozzle_id' => $nozzle->id,
            'liters' => 10,
            'monthly_payment_amount' => 200,
            'first_due_date' => today()->addMonthNoOverflow()->toDateString(),
        ])->assertCreated();
        $agreement = FuelCreditAgreement::findOrFail($agreementResponse->json('data.id'));

        $paymentResponse = $this->postJson(route('api.credit.payments.store', $agreement), [
            'amount' => 250,
            'paid_at' => now()->toIso8601String(),
            'payment_method' => 'cash',
            'reference' => 'REVERSAL-1',
        ])->assertCreated();
        $paymentId = $paymentResponse->json('data.id');

        $this->postJson(route('api.credit.payments.void', $paymentId), [
            'reason' => 'Payment was entered against the wrong account.',
        ])->assertOk()
            ->assertJsonPath('message', 'Payment reversed. Allocation and installment balances were recalculated; the original payment remains in history.');

        $payment = FuelCreditPayment::findOrFail($paymentId);
        $this->assertSame('voided', $payment->status);
        $this->assertSame('Payment was entered against the wrong account.', $payment->void_reason);
        $this->assertSame('active', $agreement->fresh()->status);
        $this->assertSame('0.00', $agreement->installments()->where('installment_number', 1)->value('amount_paid'));
        $this->assertSame('pending', $agreement->installments()->where('installment_number', 1)->value('status'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fuel-credit.payment.voided',
            'subject_id' => $paymentId,
        ]);

        $this->getJson(route('api.credit.agreements.index'))
            ->assertOk()
            ->assertJsonPath('data.data.0.balance', 597.5)
            ->assertJsonPath('data.data.0.payments.0.status', 'voided')
            ->assertJsonPath('data.data.0.payments.0.can_void', false);
        $this->postJson(route('api.credit.payments.void', $paymentId), [
            'reason' => 'Duplicate reversal attempt.',
        ])->assertConflict();
    }

    public function test_staff_use_direct_sales_and_import_screen_is_unavailable(): void
    {
        [$branch, $manager, $tank, $nozzle] = $this->station();
        $staff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $branch->id]);
        $this->actingAs($staff)->get('/sales/import')
            ->assertMethodNotAllowed();
        $this->actingAs($staff)->get(route('sales.index'))
            ->assertOk()
            ->assertSee('data-page="sales"', false)
            ->assertSee('data-role="staff"', false);

        $csv = $this->salesCsv([
            ['POS-001', now()->subMinutes(3)->format('Y-m-d H:i:s'), $nozzle->code, '5', 'R-001', 'cash'],
            ['POS-002', now()->subMinutes(2)->format('Y-m-d H:i:s'), $nozzle->code, '7', 'R-002', 'card'],
        ]);

        $this->actingAs($staff)->postJson(route('api.sales.import.preview'), [
            'file' => $csv,
        ])->assertForbidden();

        $preview = $this->actingAs($manager)->postJson(route('api.sales.import.preview'), [
            'file' => $csv,
        ])->assertCreated()
            ->assertJsonPath('data.row_count', 2);
        $this->assertSame(
            2,
            $preview->json('data.valid_row_count'),
            json_encode($this->getJson(route('api.sales.import-batches.show', $preview->json('data.id')))->json()),
        );
        $preview->assertJsonPath('data.invalid_row_count', 0)
            ->assertJsonPath('can_import', true);
        $batchId = $preview->json('data.id');

        $this->getJson(route('api.sales.import-batches.show', $batchId))
            ->assertOk()
            ->assertJsonPath('rows.data.0.source_transaction_id', 'POS-001');
        $otherBranch = Branch::create(['code' => 'remote-import', 'name' => 'Remote Import']);
        $otherManager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $otherBranch->id]);
        $this->actingAs($otherManager)->getJson(route('api.sales.import-batches.show', $batchId))
            ->assertNotFound();

        $this->actingAs($staff)->getJson(route('api.reports.sales'))->assertForbidden();

        $this->actingAs($manager)->postJson(route('api.sales.import-batches.confirm', $batchId))
            ->assertOk()
            ->assertJsonPath('data.status', 'imported');
        $this->assertSame(2, FuelSale::count());
        $this->assertSame(88.0, (float) $tank->movements()->sum('signed_quantity_liters'));
        $this->assertSame(2, InventoryMovement::where('movement_type', 'sale')->count());
        $this->assertDatabaseHas('fuel_sales', [
            'source_system_transaction_id' => 'POS-001',
            'sales_import_batch_id' => $batchId,
            'recorded_by' => $manager->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'sales-import.completed',
            'subject_id' => $batchId,
        ]);

        $sameFile = $this->salesCsv([
            ['POS-001', now()->subMinutes(3)->format('Y-m-d H:i:s'), $nozzle->code, '5', 'R-001', 'cash'],
        ]);
        $duplicatePreview = $this->postJson(route('api.sales.import.preview'), ['file' => $sameFile])
            ->assertCreated();
        $this->assertSame(0, $duplicatePreview->json('data.valid_row_count'));
        $this->assertSame(1, $duplicatePreview->json('data.invalid_row_count'));
    }

    public function test_import_rejects_duplicate_rows_and_historical_overselling_before_posting(): void
    {
        [$branch, $manager, $tank, $nozzle] = $this->station(stock: 10);
        $staff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $branch->id]);
        $csv = $this->salesCsv([
            ['DUP-1', now()->subMinutes(3)->format('Y-m-d H:i:s'), $nozzle->code, '6', 'R-1', 'cash'],
            ['DUP-1', now()->subMinutes(2)->format('Y-m-d H:i:s'), $nozzle->code, '6', 'R-2', 'cash'],
        ]);
        $this->actingAs($staff)->postJson(route('api.sales.import.preview'), ['file' => $csv])
            ->assertForbidden();
        $preview = $this->actingAs($manager)->postJson(route('api.sales.import.preview'), ['file' => $csv])
            ->assertCreated();
        $this->assertSame(
            1,
            $preview->json('data.invalid_row_count'),
            json_encode($this->getJson(route('api.sales.import-batches.show', $preview->json('data.id')))->json()),
        );
        $preview->assertJsonPath('can_import', false);
        $batchId = $preview->json('data.id');
        $this->postJson(route('api.sales.import-batches.confirm', $batchId))
            ->assertUnprocessable();
        $this->assertSame(0, FuelSale::count());
        $this->assertSame(10.0, (float) $tank->movements()->sum('signed_quantity_liters'));

        $oversellCsv = $this->salesCsv([
            ['OK-1', now()->subMinutes(3)->format('Y-m-d H:i:s'), $nozzle->code, '8', 'R-3', 'cash'],
            ['OK-2', now()->subMinutes(2)->format('Y-m-d H:i:s'), $nozzle->code, '8', 'R-4', 'cash'],
        ]);
        $oversell = $this->postJson(route('api.sales.import.preview'), ['file' => $oversellCsv])
            ->assertCreated()
            ->assertJsonPath('data.invalid_row_count', 1);
        $this->postJson(route('api.sales.import-batches.confirm', $oversell->json('data.id')))
            ->assertUnprocessable();
        $this->assertSame(0, FuelSale::count());
    }

    public function test_five_ce_pos_csv_import_records_source_price_and_deducts_the_mapped_tank(): void
    {
        Storage::fake('local');
        [$branch, $owner, $tank, $nozzle] = $this->station(stock: 1000);
        $branch->update(['code' => 'manaoag', 'name' => 'Manaoag']);
        $product = FuelProduct::create(['name' => 'Premium']);
        $tank->update(['fuel_product_id' => $product->id, 'capacity_liters' => 12000]);
        $tank->movements()->where('movement_type', 'opening_balance')->update([
            'occurred_at' => '2026-10-29 00:00:00',
        ]);
        $nozzle->update(['code' => 'B1-P1-N3']);
        $nozzle->pump->update(['code' => 'B1-P1', 'label' => 'Tatsuno P#1']);

        $csv = $this->posCsv([
            ['TXN001', '2026-10-29', '08:15:00', 'Branch 1', 'B1-P1', 'B1-P1-N3', 'Premium', '20.00', '65.50', '1310.00'],
        ]);
        $preview = $this->actingAs($owner)->postJson(route('api.sales.import.preview'), ['file' => $csv])
            ->assertCreated();
        $this->assertSame(
            1,
            $preview->json('data.valid_row_count'),
            json_encode($this->getJson($preview->json('rows_url'))->json()),
        );
        $preview->assertJsonPath('can_import', true);
        $batchId = $preview->json('data.id');

        $this->getJson(route('api.sales.import-batches.show', $batchId))
            ->assertOk()
            ->assertJsonPath('rows.data.0.branch_label', 'Branch 1')
            ->assertJsonPath('rows.data.0.pump_code', 'B1-P1')
            ->assertJsonPath('rows.data.0.fuel_grade', 'Premium')
            ->assertJsonPath('rows.data.0.price_per_liter', '65.50')
            ->assertJsonPath('rows.data.0.total_amount', '1310.00');

        $this->postJson(route('api.sales.import-batches.confirm', $batchId))
            ->assertOk()
            ->assertJsonPath('data.status', 'imported');

        $sale = FuelSale::where('source_system_transaction_id', 'TXN001')->firstOrFail();
        $this->assertSame('65.50', $sale->price_per_liter);
        $this->assertSame('1310.00', $sale->total_amount);
        $this->assertNull($sale->fuel_price_id);
        $this->assertSame(980.0, (float) $tank->movements()->sum('signed_quantity_liters'));

        $this->actingAs($owner)->put(route('sales.update', $sale), [
            'liters' => 19,
            'receipt_number' => 'TXN001-EDIT',
            'payment_method' => 'cash',
            'edit_reason' => 'Attempted edit',
        ])->assertForbidden();
        $this->actingAs($owner)->post(route('sales.void', $sale), [
            'void_reason' => 'Attempted void',
        ])->assertForbidden();
        $this->assertSame('20.000', $sale->fresh()->liters);
        $this->assertSame(980.0, (float) $tank->movements()->sum('signed_quantity_liters'));
    }

    public function test_credit_customer_can_be_edited_and_archived_without_deleting_history(): void
    {
        [$branch, $manager] = $this->station();
        $customer = $this->actingAs($manager)->postJson(route('api.credit.customers.store'), [
            'name' => 'Customer One',
            'phone' => '555-0101',
        ])->assertCreated()->json('data');

        $this->putJson(route('api.credit.customers.update', $customer['id']), [
            'name' => 'Customer Updated',
            'phone' => '555-0202',
        ])->assertOk()->assertJsonPath('data.name', 'Customer Updated');

        $this->patchJson(route('api.credit.customers.status', $customer['id']), [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('fuel_credit_customers', [
            'id' => $customer['id'],
            'branch_id' => $branch->id,
            'name' => 'Customer Updated',
            'is_active' => false,
        ]);
    }

    public function test_five_ce_pos_csv_rejects_wrong_branch_pump_grade_and_amount(): void
    {
        Storage::fake('local');
        [$branch, $owner, $tank, $nozzle] = $this->station(stock: 1000);
        $branch->update(['code' => 'manaoag', 'name' => 'Manaoag']);
        $product = FuelProduct::create(['name' => 'Premium']);
        $tank->update(['fuel_product_id' => $product->id, 'capacity_liters' => 12000]);
        $tank->movements()->where('movement_type', 'opening_balance')->update([
            'occurred_at' => '2026-10-29 00:00:00',
        ]);
        $nozzle->update(['code' => 'B1-P1-N3']);
        $nozzle->pump->update(['code' => 'B1-P1']);

        $csv = $this->posCsv([
            ['BAD-BRANCH', '2026-10-29', '08:15:00', 'Branch 2', 'B1-P1', 'B1-P1-N3', 'Premium', '20', '65.50', '1310.00'],
            ['BAD-PUMP', '2026-10-29', '08:16:00', 'Branch 1', 'B1-P2', 'B1-P1-N3', 'Premium', '20', '65.50', '1310.00'],
            ['BAD-GRADE', '2026-10-29', '08:17:00', 'Branch 1', 'B1-P1', 'B1-P1-N3', 'Diesel', '20', '65.50', '1310.00'],
            ['BAD-TOTAL', '2026-10-29', '08:18:00', 'Branch 1', 'B1-P1', 'B1-P1-N3', 'Premium', '20', '65.50', '1300.00'],
        ]);
        $preview = $this->actingAs($owner)->postJson(route('api.sales.import.preview'), ['file' => $csv])
            ->assertCreated()
            ->assertJsonPath('data.invalid_row_count', 4)
            ->assertJsonPath('can_import', false);
        $rows = $this->getJson(route('api.sales.import-batches.show', $preview->json('data.id')))
            ->assertOk()
            ->json('rows.data');

        $this->assertStringContainsString('different branch', implode(' ', $rows[0]['errors']));
        $this->assertStringContainsString('Pump_ID', implode(' ', $rows[1]['errors']));
        $this->assertStringContainsString('Fuel_Grade', implode(' ', $rows[2]['errors']));
        $this->assertStringContainsString('Total_Amount', implode(' ', $rows[3]['errors']));
        $this->assertSame(0, FuelSale::count());
    }

    public function test_monthly_csv_includes_voids_as_marked_rows_and_staff_can_be_deactivated_without_deleting_history(): void
    {
        [$branch, $manager, $tank, $nozzle, $product] = $this->station();
        $sale = FuelSale::create([
            'branch_id' => $branch->id,
            'fuel_nozzle_id' => $nozzle->id,
            'fuel_product_id' => $product->id,
            'liters' => 2,
            'price_per_liter' => 50,
            'total_amount' => 100,
            'sold_at' => now()->subDay(),
            'recorded_by' => $manager->id,
            'status' => 'voided',
            'voided_by' => $manager->id,
            'voided_at' => now(),
            'void_reason' => 'Duplicate POS entry',
        ]);
        $csv = $this->actingAs($manager)->get(route('api.reports.sales.monthly', [
            'month' => now()->format('Y-m'),
        ]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Status', $csv);
        $this->assertStringContainsString('Voided', $csv);
        $this->assertStringContainsString('Duplicate POS entry', $csv);

        $staff = User::factory()->create(['role' => UserRole::Staff, 'branch_id' => $branch->id]);
        $this->actingAs($manager)->patch(route('users.status', $staff), ['is_active' => false])
            ->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $staff->id, 'is_active' => false]);
        $this->assertDatabaseHas('fuel_sales', ['id' => $sale->id, 'status' => 'voided']);
        $this->assertNotNull(AuditLog::where('action', 'user.deactivated')->first());
    }

    public function test_delivery_invoice_is_private_and_low_stock_alerts_are_branch_scoped(): void
    {
        Storage::fake('local');
        [$branch, $manager, $tank] = $this->station(stock: 20);
        $this->actingAs($manager)->post(route('deliveries.store'), [
            'fuel_tank_id' => $tank->id,
            'supplier_name' => 'Verified Supplier',
            'delivery_reference' => 'INV-100',
            'delivered_liters' => 10,
            'cost_per_liter' => 45,
            'received_at' => now()->subMinutes(5)->toDateTimeString(),
            'invoice' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
        ])->assertRedirect(route('deliveries.index'));

        $delivery = FuelDelivery::firstOrFail();
        $this->assertNotNull($delivery->invoice_path);
        Storage::disk('local')->assertExists($delivery->invoice_path);
        $this->actingAs($manager)->get(route('deliveries.invoice', $delivery))->assertOk();
        $this->getJson(route('api.alerts.low-stock'))
            ->assertOk()
            ->assertJsonPath('data.0.tank_id', $tank->id)
            ->assertJsonPath('data.0.stock_liters', 20)
            ->assertJsonPath('data.0.threshold_liters', 20);

        $otherBranch = Branch::create(['code' => 'other-alert', 'name' => 'Other Alert']);
        $otherManager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $otherBranch->id]);
        $this->actingAs($otherManager)->get(route('deliveries.invoice', $delivery))->assertNotFound();
        $this->getJson(route('api.alerts.low-stock'))->assertOk()->assertJsonCount(0, 'data');
    }

    private function station(float $stock = 100): array
    {
        $branch = Branch::create(['code' => 'test-branch', 'name' => 'Test Branch']);
        $manager = User::factory()->create(['role' => UserRole::Owner, 'branch_id' => $branch->id]);
        $product = FuelProduct::create(['name' => 'Unleaded 91']);
        $tank = FuelTank::create([
            'branch_id' => $branch->id,
            'fuel_product_id' => $product->id,
            'code' => 'T-1',
            'capacity_liters' => 500,
            'low_stock_threshold_liters' => 20,
        ]);
        $pump = FuelPump::create(['branch_id' => $branch->id, 'code' => 'P-1']);
        $nozzle = FuelNozzle::create([
            'branch_id' => $branch->id,
            'fuel_pump_id' => $pump->id,
            'fuel_tank_id' => $tank->id,
            'code' => 'N-1',
        ]);
        $tank->inventoryMovements()->create([
            'branch_id' => $branch->id,
            'fuel_tank_id' => $tank->id,
            'movement_type' => 'opening_balance',
            'signed_quantity_liters' => $stock,
            'unit_cost_per_liter' => 40,
            'recorded_by' => $manager->id,
            'occurred_at' => now()->subHour(),
        ]);
        FuelPrice::create([
            'branch_id' => $branch->id,
            'fuel_product_id' => $product->id,
            'price_per_liter' => 59.75,
            'effective_from' => now()->subDay(),
            'set_by' => $manager->id,
        ]);

        return [$branch, $manager, $tank, $nozzle, $product];
    }

    private function salesCsv(array $rows): UploadedFile
    {
        $content = "transaction_id,sold_at,nozzle_code,liters,receipt_number,payment_method\n";
        foreach ($rows as $row) {
            $content .= implode(',', array_map(
                fn (string $value): string => '"'.str_replace('"', '""', $value).'"',
                $row,
            ))."\n";
        }

        return UploadedFile::fake()->createWithContent('daily-sales.csv', $content);
    }

    private function posCsv(array $rows): UploadedFile
    {
        $content = "Transaction_ID,Date,Time,Branch,Pump_ID,Nozzle_ID,Fuel_Grade,Liters,Price_Per_Liter,Total_Amount\n";
        foreach ($rows as $row) {
            $content .= implode(',', array_map(
                fn (string $value): string => '"'.str_replace('"', '""', $value).'"',
                $row,
            ))."\n";
        }

        return UploadedFile::fake()->createWithContent('fivece-pos.csv', $content);
    }
}

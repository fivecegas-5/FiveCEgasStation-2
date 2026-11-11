<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBranchContext;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FuelCreditAgreement;
use App\Models\FuelCreditCustomer;
use App\Models\FuelCreditInstallment;
use App\Models\FuelCreditPayment;
use App\Models\FuelCreditPaymentAllocation;
use App\Models\FuelNozzle;
use App\Models\FuelPrice;
use App\Models\FuelSale;
use App\Models\Shift;
use App\Models\User;
use App\Services\InventoryLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Throwable;

class FuelCreditController extends Controller
{
    use ResolvesBranchContext;

    public function customers(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $customers = FuelCreditCustomer::query()
            ->where('branch_id', $branchId)
            ->when(
                filled($data['search'] ?? null),
                fn (Builder $query) => $query->where(fn (Builder $search) => $search
                    ->where('name', 'like', '%'.$data['search'].'%')
                    ->orWhere('email', 'like', '%'.$data['search'].'%')
                    ->orWhere('phone', 'like', '%'.$data['search'].'%')),
            )
            ->withCount('agreements')
            ->orderBy('name')
            ->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $customers]);
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('fuel_credit_customers', 'email')->where('branch_id', $branchId)],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $customer = DB::transaction(function () use ($request, $data, $branchId): FuelCreditCustomer {
            $branch = Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
            abort_unless($branch->is_active, 422, 'This branch is inactive.');

            $customer = FuelCreditCustomer::create([
                'branch_id' => $branchId,
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $this->audit($request->user(), $branchId, 'fuel-credit.customer.created', $customer, null, [
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ]);

            return $customer;
        });

        return response()->json(['data' => $customer], 201);
    }

    public function updateCustomer(Request $request, FuelCreditCustomer $customer): JsonResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $customer->branch_id), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('fuel_credit_customers', 'email')
                ->where('branch_id', $customer->branch_id)
                ->ignore($customer->id)],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);
        DB::transaction(function () use ($request, $customer, $data): void {
            $lockedCustomer = FuelCreditCustomer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $before = ['name' => $lockedCustomer->name, 'email' => $lockedCustomer->email, 'phone' => $lockedCustomer->phone];
            $lockedCustomer->update($data);
            $this->audit($request->user(), (int) $lockedCustomer->branch_id, 'fuel-credit.customer.updated', $lockedCustomer, $before, [
                'name' => $lockedCustomer->name,
                'email' => $lockedCustomer->email,
                'phone' => $lockedCustomer->phone,
            ]);
        });

        return response()->json(['data' => $customer->fresh()]);
    }

    public function exportCustomers(Request $request)
    {
        $branchId = $this->branchId($request);
        $customers = FuelCreditCustomer::query()->where('branch_id', $branchId)->orderBy('name')->orderBy('id');

        return response()->streamDownload(function () use ($customers): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Name', 'Email', 'Phone']);

            foreach ($customers->lazy(500) as $customer) {
                fputcsv($output, [
                    $this->safeCsvText($customer->name),
                    $this->safeCsvText($customer->email),
                    $this->safeCsvText($customer->phone),
                ]);
            }

            fclose($output);
        }, 'fuel-credit-customers-branch-'.$branchId.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function importCustomers(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);
        $file = $request->file('file');
        $path = $file->getRealPath();
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The uploaded customer file could not be read.']);
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'The uploaded customer file could not be read.']);
        }

        try {
            $headers = fgetcsv($handle, null, ',', '"', '');
            if ($headers === false) {
                throw ValidationException::withMessages(['file' => 'The customer CSV is empty.']);
            }
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
            $headers = array_map(static fn ($header): string => strtolower(trim((string) $header)), $headers);
            if (count($headers) !== 3 || $headers !== ['name', 'email', 'phone']) {
                throw ValidationException::withMessages(['file' => 'Use the customer CSV template with Name, Email, and Phone columns in that order.']);
            }

            $rows = [];
            $errors = [];
            $emails = [];
            $rowNumber = 1;
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $rowNumber++;
                if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                    continue;
                }
                if (count($rows) >= 5000) {
                    throw ValidationException::withMessages(['file' => 'A customer import may contain at most 5,000 rows.']);
                }
                if (count($row) !== 3) {
                    $errors["row_{$rowNumber}"] = "Row {$rowNumber} must contain exactly three columns.";
                    continue;
                }

                $customer = [
                    'name' => trim((string) $row[0]),
                    'email' => trim((string) $row[1]) ?: null,
                    'phone' => trim((string) $row[2]) ?: null,
                ];
                $validator = Validator::make($customer, [
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['nullable', 'email', 'max:255', Rule::unique('fuel_credit_customers', 'email')->where('branch_id', $branchId)],
                    'phone' => ['nullable', 'string', 'max:40'],
                ]);
                if ($validator->fails()) {
                    $errors["row_{$rowNumber}"] = "Row {$rowNumber}: ".$validator->errors()->first();
                    continue;
                }
                if ($customer['email'] !== null) {
                    $emailKey = strtolower($customer['email']);
                    if (isset($emails[$emailKey])) {
                        $errors["row_{$rowNumber}"] = "Row {$rowNumber}: Email is duplicated in this file.";
                        continue;
                    }
                    $emails[$emailKey] = true;
                }
                $rows[] = $customer;
            }
        } finally {
            fclose($handle);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(array_slice($errors, 0, 50, true));
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The customer CSV contains no customer rows.']);
        }

        DB::transaction(function () use ($request, $rows, $branchId): void {
            $branch = Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
            abort_unless($branch->is_active, 422, 'This branch is inactive.');
            foreach ($rows as $data) {
                $customer = FuelCreditCustomer::create([
                    ...$data,
                    'branch_id' => $branchId,
                    'created_by' => $request->user()->id,
                ]);
                $this->audit($request->user(), $branchId, 'fuel-credit.customer.imported', $customer, null, $data);
            }
        });

        return response()->json(['message' => count($rows).' customer records imported.', 'imported' => count($rows)], 201);
    }

    public function updateCustomerStatus(Request $request, FuelCreditCustomer $customer): JsonResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $customer->branch_id), 404);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $isActive = (bool) $data['is_active'];
        DB::transaction(function () use ($request, $customer, $isActive): void {
            $lockedCustomer = FuelCreditCustomer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $before = ['is_active' => $lockedCustomer->is_active];
            $lockedCustomer->update(['is_active' => $isActive]);
            $this->audit(
                $request->user(),
                (int) $lockedCustomer->branch_id,
                $isActive ? 'fuel-credit.customer.activated' : 'fuel-credit.customer.archived',
                $lockedCustomer,
                $before,
                ['is_active' => $isActive],
            );
        });

        return response()->json(['data' => $customer->fresh()]);
    }

    public function agreements(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'paid'])],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $agreements = FuelCreditAgreement::query()
            ->where('branch_id', $branchId)
            ->when(isset($data['status']), fn (Builder $query) => $query->where('status', $data['status']))
            ->whereHas(
                'customer',
                fn (Builder $query) => $query->when(
                    filled($data['search'] ?? null),
                    fn (Builder $customerQuery) => $customerQuery->where('name', 'like', '%'.$data['search'].'%'),
                ),
            )
            ->with([
                'customer',
                'fuelProduct',
                'nextPendingInstallment',
                'installments' => fn (HasMany $query) => $query->orderBy('due_date')->orderBy('installment_number'),
                'payments.recorder',
            ])
            ->withSum(['payments as paid_amount' => fn (Builder $query) => $query->where('status', 'posted')], 'amount')
            ->latest()
            ->paginate($data['per_page'] ?? 25)
            ->through(fn (FuelCreditAgreement $agreement): array => $this->agreementSummary($agreement));

        return response()->json(['data' => $agreements]);
    }

    public function storeAgreement(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'fuel_credit_customer_id' => ['required', 'integer'],
            'fuel_nozzle_id' => ['required', 'integer'],
            'liters' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'monthly_payment_amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'first_due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'agreement_reference' => ['nullable', 'string', 'max:100'],
            'agreement' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $upload = $request->file('agreement');
        $storedPath = null;

        try {
            $agreement = DB::transaction(function () use ($request, $data, $branchId, $upload, &$storedPath): FuelCreditAgreement {
                $branch = Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
                abort_unless($branch->is_active, 422, 'This branch is inactive.');
                $customer = FuelCreditCustomer::query()
                    ->where('branch_id', $branchId)
                    ->where('is_active', true)
                    ->whereKey($data['fuel_credit_customer_id'])
                    ->firstOrFail();
                if (filled($data['agreement_reference'] ?? null)
                    && FuelCreditAgreement::query()
                        ->where('branch_id', $branchId)
                        ->where('agreement_reference', $data['agreement_reference'])
                        ->exists()) {
                    throw ValidationException::withMessages([
                        'agreement_reference' => 'This agreement reference is already used in the branch.',
                    ]);
                }

                $nozzle = FuelNozzle::query()
                    ->where('branch_id', $branchId)
                    ->where('is_active', true)
                    ->whereKey($data['fuel_nozzle_id'])
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_unless($nozzle->pump->is_active, 422, 'The connected pump is inactive.');
                $tank = app(InventoryLedger::class)->lockTank($branchId, $nozzle->fuel_tank_id);
                abort_unless($tank->fuelProduct->is_active, 422, 'This fuel product is inactive.');

                $issuedAt = now();
                $price = FuelPrice::query()
                    ->where('branch_id', $branchId)
                    ->where('fuel_product_id', $tank->fuel_product_id)
                    ->where('effective_from', '<=', $issuedAt)
                    ->orderByDesc('effective_from')
                    ->first();
                if (! $price) {
                    throw ValidationException::withMessages([
                        'fuel_nozzle_id' => 'Set an effective fuel price for this product before recording a credit sale.',
                    ]);
                }

                $ledger = app(InventoryLedger::class);
                $liters = (float) $data['liters'];
                $ledger->assertBalanceWithinCapacity($tank, -$liters);
                $unitCost = $ledger->weightedAverageCost($tank);
                $amount = round($liters * (float) $price->price_per_liter, 2);
                $monthlyPayment = round((float) $data['monthly_payment_amount'], 2);
                $installmentCount = (int) ceil($amount / $monthlyPayment);
                if ($installmentCount < 1 || $installmentCount > 600) {
                    throw ValidationException::withMessages([
                        'monthly_payment_amount' => 'The payment schedule must not exceed 600 monthly installments.',
                    ]);
                }

                $sale = FuelSale::create([
                    'branch_id' => $branchId,
                    'fuel_nozzle_id' => $nozzle->id,
                    'fuel_product_id' => $tank->fuel_product_id,
                    'shift_id' => Shift::query()
                        ->where('branch_id', $branchId)
                        ->where('status', 'open')
                        ->latest('opened_at')
                        ->value('id'),
                    'fuel_price_id' => $price->id,
                    'liters' => $liters,
                    'price_per_liter' => $price->price_per_liter,
                    'total_amount' => $amount,
                    'payment_method' => 'credit',
                    'sold_at' => $issuedAt,
                    'recorded_by' => $request->user()->id,
                ]);
                $ledger->record(
                    $tank,
                    'sale',
                    -$liters,
                    $request->user(),
                    $sale,
                    $issuedAt,
                    $unitCost,
                );

                $agreement = FuelCreditAgreement::create([
                    'branch_id' => $branchId,
                    'fuel_credit_customer_id' => $customer->id,
                    'fuel_sale_id' => $sale->id,
                    'fuel_nozzle_id' => $nozzle->id,
                    'fuel_product_id' => $tank->fuel_product_id,
                    'agreement_reference' => $data['agreement_reference'] ?? null,
                    'liters' => $liters,
                    'price_per_liter' => $price->price_per_liter,
                    'principal_amount' => $amount,
                    'monthly_payment_amount' => $monthlyPayment,
                    'first_due_date' => $data['first_due_date'],
                    'status' => 'active',
                    'recorded_by' => $request->user()->id,
                ]);

                if ($upload) {
                    $storedPath = $upload->store('fuel-credit-agreements', 'local');
                    if ($storedPath === false) {
                        throw new \RuntimeException('The agreement document could not be stored.');
                    }
                    $agreement->update([
                        'agreement_file_path' => $storedPath,
                        'agreement_original_name' => $upload->getClientOriginalName(),
                        'agreement_mime_type' => $upload->getMimeType(),
                        'agreement_size_bytes' => $upload->getSize(),
                    ]);
                }

                $remainingCents = (int) round($amount * 100);
                $monthlyCents = (int) round($monthlyPayment * 100);
                $dueDate = Carbon::parse($data['first_due_date']);
                for ($number = 1; $remainingCents > 0; $number++) {
                    $installmentCents = min($monthlyCents, $remainingCents);
                    FuelCreditInstallment::create([
                        'fuel_credit_agreement_id' => $agreement->id,
                        'installment_number' => $number,
                        'due_date' => $dueDate->toDateString(),
                        'amount_due' => number_format($installmentCents / 100, 2, '.', ''),
                        'amount_paid' => 0,
                        'status' => 'pending',
                    ]);
                    $remainingCents -= $installmentCents;
                    $dueDate->addMonthNoOverflow();
                }

                $this->audit($request->user(), $branchId, 'fuel-credit.agreement.created', $agreement, null, [
                    'customer_id' => $customer->id,
                    'fuel_sale_id' => $sale->id,
                    'principal_amount' => $amount,
                    'monthly_payment_amount' => $monthlyPayment,
                    'installment_count' => $installmentCount,
                ]);

                return $agreement;
            });
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }

        $agreement->load([
            'customer',
            'fuelProduct',
            'nextPendingInstallment',
            'installments' => fn (HasMany $query) => $query->orderBy('due_date')->orderBy('installment_number'),
        ]);
        $agreement->loadSum(
            ['payments as paid_amount' => fn (Builder $query) => $query->where('status', 'posted')],
            'amount',
        );

        return response()->json([
            'data' => $this->agreementSummary($agreement) + [
                'installments' => $agreement->installments->map(fn (FuelCreditInstallment $installment): array => [
                    'installment_number' => $installment->installment_number,
                    'due_date' => $installment->due_date->toDateString(),
                    'amount_due' => (float) $installment->amount_due,
                    'amount_paid' => (float) $installment->amount_paid,
                    'status' => $installment->status,
                ]),
            ],
        ], 201);
    }

    public function showAgreement(Request $request, FuelCreditAgreement $agreement): JsonResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $agreement->branch_id), 404);
        $agreement->load([
            'branch',
            'customer',
            'fuelProduct',
            'nozzle.pump',
            'sale',
            'recorder',
            'installments' => fn (HasMany $query) => $query->orderBy('due_date')->orderBy('installment_number'),
            'payments.recorder',
            'payments.allocations.installment',
        ]);
        $agreement->loadSum(
            ['payments as paid_amount' => fn (Builder $query) => $query->where('status', 'posted')],
            'amount',
        );

        return response()->json([
            'data' => $this->agreementSummary($agreement) + [
                'branch' => $agreement->branch->name,
                'sale_id' => $agreement->fuel_sale_id,
                'nozzle' => $agreement->nozzle->code,
                'agreement_file_name' => $agreement->agreement_original_name,
                'installments' => $agreement->installments->map(fn (FuelCreditInstallment $installment): array => [
                    'installment_number' => $installment->installment_number,
                    'due_date' => $installment->due_date->toDateString(),
                    'amount_due' => (float) $installment->amount_due,
                    'amount_paid' => (float) $installment->amount_paid,
                    'balance' => round((float) $installment->amount_due - (float) $installment->amount_paid, 2),
                    'status' => $installment->status,
                ]),
                'payments' => $agreement->payments->map(fn (FuelCreditPayment $payment): array => [
                    'id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'paid_at' => $payment->paid_at,
                    'payment_method' => $payment->payment_method,
                    'reference' => $payment->reference,
                    'notes' => $payment->notes,
                    'recorded_by' => $payment->recorder->name,
                    'status' => $payment->status,
                    'voided_at' => $payment->voided_at,
                    'void_reason' => $payment->void_reason,
                    'allocations' => $payment->allocations->map(fn (FuelCreditPaymentAllocation $allocation): array => [
                        'installment_number' => $allocation->installment->installment_number,
                        'amount' => (float) $allocation->amount,
                    ]),
                ]),
            ],
        ]);
    }

    public function storePayment(Request $request, FuelCreditAgreement $agreement): JsonResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $agreement->branch_id), 404);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
            'payment_method' => ['nullable', Rule::in(['cash', 'card', 'ewallet', 'bank_transfer', 'other'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payment = DB::transaction(function () use ($request, $agreement, $data): FuelCreditPayment {
            $lockedAgreement = FuelCreditAgreement::query()
                ->whereKey($agreement->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedAgreement->status === 'active', 409, 'This credit agreement is already paid.');

            $amountCents = (int) round((float) $data['amount'] * 100);
            $paidCents = (int) round(
                (float) $lockedAgreement->payments()->where('status', 'posted')->sum('amount') * 100,
            );
            $principalCents = (int) round((float) $lockedAgreement->principal_amount * 100);
            if ($amountCents > $principalCents - $paidCents) {
                throw ValidationException::withMessages([
                    'amount' => 'The payment cannot exceed the outstanding balance.',
                ]);
            }

            $payment = FuelCreditPayment::create([
                'fuel_credit_agreement_id' => $lockedAgreement->id,
                'recorded_by' => $request->user()->id,
                'amount' => number_format($amountCents / 100, 2, '.', ''),
                'paid_at' => $data['paid_at'],
                'payment_method' => $data['payment_method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $remainingCents = $amountCents;
            $installments = $lockedAgreement->installments()
                ->where('status', '!=', 'paid')
                ->orderBy('due_date')
                ->orderBy('installment_number')
                ->lockForUpdate()
                ->get();

            foreach ($installments as $installment) {
                if ($remainingCents <= 0) {
                    break;
                }

                $dueCents = (int) round((float) $installment->amount_due * 100);
                $alreadyPaidCents = (int) round((float) $installment->amount_paid * 100);
                $appliedCents = min($remainingCents, $dueCents - $alreadyPaidCents);
                if ($appliedCents <= 0) {
                    continue;
                }

                FuelCreditPaymentAllocation::create([
                    'fuel_credit_payment_id' => $payment->id,
                    'fuel_credit_installment_id' => $installment->id,
                    'amount' => number_format($appliedCents / 100, 2, '.', ''),
                ]);
                $alreadyPaidCents += $appliedCents;
                $installment->update([
                    'amount_paid' => number_format($alreadyPaidCents / 100, 2, '.', ''),
                    'status' => $alreadyPaidCents >= $dueCents ? 'paid' : 'partial',
                ]);
                $remainingCents -= $appliedCents;
            }

            $newPaidCents = $paidCents + $amountCents;
            if ($newPaidCents >= $principalCents) {
                $lockedAgreement->update(['status' => 'paid']);
            }

            $this->audit(
                $request->user(),
                (int) $lockedAgreement->branch_id,
                'fuel-credit.payment.recorded',
                $payment,
                null,
                [
                    'agreement_id' => $lockedAgreement->id,
                    'amount' => $payment->amount,
                    'paid_at' => $payment->paid_at,
                    'allocations' => $payment->allocations()->get(['fuel_credit_installment_id', 'amount'])->toArray(),
                    'balance' => number_format(max(0, $principalCents - $newPaidCents) / 100, 2, '.', ''),
                ],
            );

            return $payment->load('allocations.installment');
        });

        return response()->json(['data' => $payment], 201);
    }

    public function voidAgreement(Request $request, FuelCreditAgreement $agreement): JsonResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $agreement->branch_id), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        DB::transaction(function () use ($request, $agreement, $data): void {
            $lockedAgreement = FuelCreditAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedAgreement->status === 'active', 409, 'Only active agreements can be cancelled.');
            abort_if(
                $lockedAgreement->payments()->where('status', 'posted')->exists(),
                409,
                'An agreement with recorded payments cannot be cancelled; contact the administrator to resolve the payment history.',
            );

            $sale = FuelSale::query()->whereKey($lockedAgreement->fuel_sale_id)->lockForUpdate()->firstOrFail();
            abort_if($sale->sales_import_batch_id !== null, 403, 'Imported sales are read-only and cannot be changed.');
            abort_unless($sale->status === 'completed', 409, 'The linked sale is no longer active.');
            $saleMovement = $sale->inventoryMovements()->where('movement_type', 'sale')->oldest('id')->firstOrFail();
            $tank = \App\Models\FuelTank::query()
                ->where('branch_id', $sale->branch_id)
                ->whereKey($saleMovement->fuel_tank_id)
                ->lockForUpdate()
                ->firstOrFail();
            $agreementBefore = $lockedAgreement->getAttributes();
            $saleBefore = $sale->getAttributes();

            app(InventoryLedger::class)->record(
                $tank,
                'credit_sale_void',
                -((float) $saleMovement->signed_quantity_liters),
                $request->user(),
                $sale,
                now(),
                $saleMovement->unit_cost_per_liter === null ? null : (float) $saleMovement->unit_cost_per_liter,
                'reason',
            );
            $sale->update([
                'status' => 'voided',
                'voided_by' => $request->user()->id,
                'voided_at' => now(),
                'void_reason' => $data['reason'],
            ]);
            $lockedAgreement->update(['status' => 'cancelled']);
            $lockedAgreement->installments()->where('status', 'pending')->update(['status' => 'cancelled']);

            $this->audit($request->user(), (int) $lockedAgreement->branch_id, 'fuel-credit.agreement.cancelled', $lockedAgreement, $agreementBefore, [
                ...$lockedAgreement->getAttributes(),
                'reason' => $data['reason'],
            ]);
            $this->audit($request->user(), (int) $sale->branch_id, 'fuel-sale.voided', $sale, $saleBefore, $sale->getAttributes());
        });

        return response()->json(['message' => 'Agreement cancelled and the fuel sale was reversed in the inventory ledger.']);
    }

    public function voidPayment(Request $request, FuelCreditPayment $payment): JsonResponse
    {
        $agreement = $payment->agreement;
        abort_unless($request->user()->canAccessBranch((int) $agreement->branch_id), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        DB::transaction(function () use ($request, $payment, $data): void {
            $lockedPayment = FuelCreditPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $agreement = FuelCreditAgreement::query()
                ->whereKey($lockedPayment->fuel_credit_agreement_id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedPayment->status === 'posted', 409, 'Only posted payments can be reversed.');
            abort_unless($agreement->status !== 'cancelled', 409, 'Payments on a cancelled agreement cannot be changed.');

            $before = $lockedPayment->getAttributes();
            $lockedPayment->update([
                'status' => 'voided',
                'voided_by' => $request->user()->id,
                'voided_at' => now(),
                'void_reason' => $data['reason'],
            ]);

            foreach ($agreement->installments()->lockForUpdate()->get() as $installment) {
                $paidCents = (int) round(
                    (float) $installment->allocations()
                        ->whereHas('payment', fn (Builder $query) => $query->where('status', 'posted'))
                        ->sum('amount') * 100,
                );
                $dueCents = (int) round((float) $installment->amount_due * 100);
                $installment->update([
                    'amount_paid' => number_format($paidCents / 100, 2, '.', ''),
                    'status' => $paidCents >= $dueCents ? 'paid' : ($paidCents > 0 ? 'partial' : 'pending'),
                ]);
            }

            $paidCents = (int) round((float) $agreement->payments()->where('status', 'posted')->sum('amount') * 100);
            $principalCents = (int) round((float) $agreement->principal_amount * 100);
            $agreement->update(['status' => $paidCents >= $principalCents ? 'paid' : 'active']);
            $this->audit($request->user(), (int) $agreement->branch_id, 'fuel-credit.payment.voided', $lockedPayment, $before, [
                ...$lockedPayment->getAttributes(),
                'reason' => $data['reason'],
                'agreement_balance' => number_format(max(0, $principalCents - $paidCents) / 100, 2, '.', ''),
            ]);
        });

        return response()->json(['message' => 'Payment reversed. Allocation and installment balances were recalculated; the original payment remains in history.']);
    }

    public function agreementDocument(Request $request, FuelCreditAgreement $agreement)
    {
        abort_unless($request->user()->canAccessBranch((int) $agreement->branch_id), 404);
        abort_unless($agreement->agreement_file_path !== null, 404, 'No agreement document is attached.');
        abort_unless(Storage::disk('local')->exists($agreement->agreement_file_path), 404, 'The stored agreement document is unavailable.');

        return Storage::disk('local')->download(
            $agreement->agreement_file_path,
            $agreement->agreement_original_name ?? 'fuel-credit-agreement',
        );
    }

    private function agreementSummary(FuelCreditAgreement $agreement): array
    {
        $paidCents = (int) round((float) ($agreement->paid_amount ?? 0) * 100);
        $principalCents = (int) round((float) $agreement->principal_amount * 100);
        $next = $agreement->nextPendingInstallment;

        return [
            'id' => $agreement->id,
            'status' => $agreement->status,
            'customer' => $agreement->customer,
            'fuel' => $agreement->fuelProduct->name,
            'liters' => (float) $agreement->liters,
            'price_per_liter' => (float) $agreement->price_per_liter,
            'value_borrowed' => (float) $agreement->principal_amount,
            'balance' => round(max(0, $principalCents - $paidCents) / 100, 2),
            'monthly_payment_amount' => (float) $agreement->monthly_payment_amount,
            'pending_payment_date' => $next?->due_date?->toDateString(),
            'pending_payment_amount' => $next
                ? round(max(0, (float) $next->amount_due - (float) $next->amount_paid), 2)
                : 0,
            'pending_payment_status' => $next
                ? ($next->due_date->isBefore(today()) ? 'overdue' : $next->status)
                : 'paid',
            'installments' => $agreement->relationLoaded('installments')
                ? $agreement->installments->map(fn (FuelCreditInstallment $installment): array => [
                    'installment_number' => $installment->installment_number,
                    'due_date' => $installment->due_date->toDateString(),
                    'amount_due' => (float) $installment->amount_due,
                    'amount_paid' => (float) $installment->amount_paid,
                    'balance' => round((float) $installment->amount_due - (float) $installment->amount_paid, 2),
                    'status' => $installment->status,
                ])->all()
                : [],
            'agreement_reference' => $agreement->agreement_reference,
            'agreement_document_available' => $agreement->agreement_file_path !== null,
            'created_at' => $agreement->created_at,
            'can_cancel' => $agreement->status === 'active' && $paidCents === 0,
            'payments' => $agreement->relationLoaded('payments')
                ? $agreement->payments->map(fn (FuelCreditPayment $payment): array => [
                    'id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                    'payment_method' => $payment->payment_method,
                    'reference' => $payment->reference,
                    'status' => $payment->status,
                    'can_void' => $payment->status === 'posted',
                ])->values()
                : [],
        ];
    }

    private function audit(
        User $actor,
        int $branchId,
        string $action,
        object $subject,
        ?array $before,
        ?array $after,
    ): void {
        AuditLog::create([
            'branch_id' => $branchId,
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'occurred_at' => now(),
        ]);
    }

    private function safeCsvText(?string $value): ?string
    {
        if ($value !== null && preg_match('/^[\s]*[=+\-@]/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBranchContext;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FuelNozzle;
use App\Models\FuelPrice;
use App\Models\FuelSale;
use App\Models\FuelTank;
use App\Models\SalesImportBatch;
use App\Models\SalesImportRow;
use App\Models\Shift;
use App\Models\User;
use App\Services\InventoryLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SalesImportController extends Controller
{
    use ResolvesBranchContext;

    public function page(): View
    {
        return view('operations.sales-import');
    }

    public function index(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $batches = SalesImportBatch::query()
            ->where('branch_id', $branchId)
            ->with('importer')
            ->latest()
            ->paginate(25)
            ->through(fn (SalesImportBatch $batch): array => $this->batchSummary($batch));

        return response()->json(['data' => $batches]);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'Transaction_ID',
                'Date',
                'Time',
                'Branch',
                'Pump_ID',
                'Nozzle_ID',
                'Fuel_Grade',
                'Liters',
                'Price_Per_Liter',
                'Total_Amount',
            ]);
            fclose($output);
        }, 'fivecetrack-daily-sales-import-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);
        $file = $data['file'];
        $hash = hash_file('sha256', $file->getRealPath());
        $existingBatch = SalesImportBatch::query()
            ->where('branch_id', $branchId)
            ->where('content_sha256', $hash)
            ->first();

        if ($existingBatch) {
            if ($existingBatch->status === 'imported') {
                throw ValidationException::withMessages([
                    'file' => 'This exact file has already been imported for this branch.',
                ]);
            }

            return response()->json([
                'data' => $this->batchSummary($existingBatch),
                'rows_url' => route('api.sales.import-batches.show', $existingBatch),
                'message' => 'This file already has a preview. Review it and confirm or upload a corrected file.',
            ]);
        }

        $rawRows = $this->readCsv($file);
        $validatedRows = $this->validateRows($branchId, $rawRows);
        $storedPath = null;

        try {
            $batch = DB::transaction(function () use ($request, $branchId, $file, $hash, $validatedRows, &$storedPath): SalesImportBatch {
                $branch = Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
                abort_unless($branch->is_active, 422, 'This branch is inactive.');
                $storedPath = $file->store('sales-imports', 'local');
                if ($storedPath === false) {
                    throw new \RuntimeException('The sales import file could not be stored.');
                }

                $invalidCount = collect($validatedRows)->where('status', 'invalid')->count();
                $batch = SalesImportBatch::create([
                    'branch_id' => $branchId,
                    'imported_by' => $request->user()->id,
                    'original_filename' => $file->getClientOriginalName(),
                    'file_path' => $storedPath,
                    'content_sha256' => $hash,
                    'status' => 'previewed',
                    'row_count' => count($validatedRows),
                    'valid_row_count' => count($validatedRows) - $invalidCount,
                    'invalid_row_count' => $invalidCount,
                ]);

                foreach ($validatedRows as $row) {
                    SalesImportRow::create([
                        'sales_import_batch_id' => $batch->id,
                        'row_number' => $row['row_number'],
                        'source_transaction_id' => $row['source_transaction_id'],
                        'branch_label' => $row['branch_label'],
                        'pump_code' => $row['pump_code'],
                        'fuel_grade' => $row['fuel_grade'],
                        'sold_at' => $row['sold_at'],
                        'nozzle_code' => $row['nozzle_code'],
                        'liters' => $row['liters'],
                        'price_per_liter' => $row['price_per_liter'],
                        'total_amount' => $row['total_amount'],
                        'receipt_number' => $row['receipt_number'],
                        'payment_method' => $row['payment_method'],
                        'status' => $row['status'],
                        'errors' => $row['errors'],
                    ]);
                }

                $this->audit($request->user(), $branchId, 'sales-import.previewed', $batch, null, [
                    'row_count' => $batch->row_count,
                    'valid_row_count' => $batch->valid_row_count,
                    'invalid_row_count' => $batch->invalid_row_count,
                    'file_sha256' => $hash,
                ]);

                return $batch;
            });
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }

        return response()->json([
            'data' => $this->batchSummary($batch),
            'rows_url' => route('api.sales.import-batches.show', $batch),
            'can_import' => $batch->invalid_row_count === 0 && $batch->row_count > 0,
        ], 201);
    }

    public function show(Request $request, SalesImportBatch $batch): JsonResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $batch->branch_id), 404);
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $rows = $batch->rows()
            ->orderBy('row_number')
            ->paginate($data['per_page'] ?? 50);

        return response()->json([
            'data' => $this->batchSummary($batch),
            'rows' => $rows,
            'can_import' => $batch->status === 'previewed'
                && $batch->invalid_row_count === 0
                && $batch->row_count > 0,
        ]);
    }

    public function confirm(Request $request, SalesImportBatch $batch): JsonResponse
    {
        abort_unless($request->user()->canAccessBranch((int) $batch->branch_id), 404);

        $result = DB::transaction(function () use ($request, $batch): array {
            $lockedBatch = SalesImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedBatch->status === 'previewed', 409, 'This import batch is no longer awaiting confirmation.');

            $rows = $lockedBatch->rows()->orderBy('sold_at')->orderBy('row_number')->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['file' => 'The import file contains no sales rows.']);
            }

            $revalidated = $this->validateRows(
                (int) $lockedBatch->branch_id,
                $rows->map(fn (SalesImportRow $row): array => [
                    'row_number' => $row->row_number,
                    'source_transaction_id' => $row->source_transaction_id,
                    'branch_label' => $row->branch_label,
                    'pump_code' => $row->pump_code,
                    'fuel_grade' => $row->fuel_grade,
                    'sold_at' => $row->sold_at->toIso8601String(),
                    'nozzle_code' => $row->nozzle_code,
                    'liters' => $row->liters,
                    'price_per_liter' => $row->price_per_liter,
                    'total_amount' => $row->total_amount,
                    'receipt_number' => $row->receipt_number,
                    'payment_method' => $row->payment_method,
                ])->all(),
            );
            $invalidRows = collect($revalidated)->where('status', 'invalid');
            if ($invalidRows->isNotEmpty()) {
                foreach ($revalidated as $index => $row) {
                    $rows[$index]->update(['status' => $row['status'], 'errors' => $row['errors']]);
                }
                $lockedBatch->update([
                    'valid_row_count' => count($revalidated) - $invalidRows->count(),
                    'invalid_row_count' => $invalidRows->count(),
                ]);

                return ['batch' => $lockedBatch->fresh(), 'invalid' => true];
            }

            $branch = Branch::query()->whereKey($lockedBatch->branch_id)->lockForUpdate()->firstOrFail();
            abort_unless($branch->is_active, 422, 'This branch is inactive.');
            $ledger = app(InventoryLedger::class);
            foreach ($rows as $row) {
                $nozzle = FuelNozzle::query()
                    ->where('branch_id', $lockedBatch->branch_id)
                    ->where('is_active', true)
                    ->where('code', $row->nozzle_code)
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_unless($nozzle->pump->is_active, 422, "Pump for nozzle {$nozzle->code} is inactive.");
                $tank = $ledger->lockTank((int) $lockedBatch->branch_id, (int) $nozzle->fuel_tank_id);
                $product = $tank->fuelProduct;
                abort_unless($product->is_active, 422, "Fuel product for nozzle {$nozzle->code} is inactive.");
                $soldAt = $row->sold_at;
                $effectivePrice = FuelPrice::query()
                    ->where('branch_id', $lockedBatch->branch_id)
                    ->where('fuel_product_id', $product->id)
                    ->where('effective_from', '<=', $soldAt)
                    ->orderByDesc('effective_from')
                    ->first();
                $unitPrice = $row->price_per_liter !== null
                    ? (float) $row->price_per_liter
                    : (float) $effectivePrice->price_per_liter;
                $fuelPriceId = $effectivePrice
                    && abs((float) $effectivePrice->price_per_liter - $unitPrice) < 0.0005
                        ? $effectivePrice->id
                        : null;
                $liters = (float) $row->liters;
                $totalAmount = $row->total_amount !== null
                    ? (float) $row->total_amount
                    : round($liters * $unitPrice, 2);
                $ledger->assertBalanceWithinCapacity($tank, -$liters);
                $unitCost = $ledger->weightedAverageCost($tank, $soldAt);
                $shiftId = Shift::query()
                    ->where('branch_id', $lockedBatch->branch_id)
                    ->where('opened_at', '<=', $soldAt)
                    ->where(fn (Builder $query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', $soldAt))
                    ->latest('opened_at')
                    ->value('id');
                $sale = FuelSale::create([
                    'branch_id' => $lockedBatch->branch_id,
                    'fuel_nozzle_id' => $nozzle->id,
                    'fuel_product_id' => $product->id,
                    'shift_id' => $shiftId,
                    'fuel_price_id' => $fuelPriceId,
                    'receipt_number' => $row->receipt_number ?: null,
                    'liters' => $liters,
                    'price_per_liter' => $unitPrice,
                    'total_amount' => $totalAmount,
                    'payment_method' => $row->payment_method,
                    'sold_at' => $soldAt,
                    'recorded_by' => $request->user()->id,
                    'sales_import_batch_id' => $lockedBatch->id,
                    'source_system_transaction_id' => $row->source_transaction_id,
                ]);
                $ledger->record(
                    $tank,
                    'sale',
                    -$liters,
                    $request->user(),
                    $sale,
                    $soldAt,
                    $unitCost,
                );
                $row->update(['status' => 'imported', 'fuel_sale_id' => $sale->id, 'errors' => null]);
            }

            $lockedBatch->update([
                'status' => 'imported',
                'valid_row_count' => $rows->count(),
                'invalid_row_count' => 0,
                'imported_at' => now(),
            ]);
            $this->audit($request->user(), (int) $lockedBatch->branch_id, 'sales-import.completed', $lockedBatch, null, [
                'row_count' => $rows->count(),
                'imported_at' => now()->toIso8601String(),
            ]);

            return ['batch' => $lockedBatch->load('importer'), 'invalid' => false];
        });

        if ($result['invalid']) {
            return response()->json([
                'data' => $this->batchSummary($result['batch']),
                'rows_url' => route('api.sales.import-batches.show', $result['batch']),
                'can_import' => false,
                'message' => 'The import no longer passes validation. Review the updated row errors before confirming.',
            ], 422);
        }

        /** @var SalesImportBatch $confirmed */
        $confirmed = $result['batch'];

        return response()->json([
            'data' => $this->batchSummary($confirmed),
            'message' => 'Sales import confirmed; all valid rows were posted to sales and inventory.',
        ]);
    }

    private function readCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'The uploaded CSV file could not be read.']);
        }

        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                throw ValidationException::withMessages(['file' => 'The CSV file is empty.']);
            }
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
            $headers = array_map(
                fn ($header): string => trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $header))), '_'),
                $headers,
            );
            if (count($headers) !== count(array_unique($headers))) {
                throw ValidationException::withMessages(['file' => 'The CSV file contains duplicate column names.']);
            }
            $headerMap = [
                'transaction_id' => 'source_transaction_id',
                'sold_at' => 'sold_at',
                'date' => 'date',
                'time' => 'time',
                'branch' => 'branch_label',
                'pump_id' => 'pump_code',
                'nozzle_code' => 'nozzle_code',
                'nozzle_id' => 'nozzle_code',
                'fuel_grade' => 'fuel_grade',
                'liters' => 'liters',
                'price_per_liter' => 'price_per_liter',
                'total_amount' => 'total_amount',
                'receipt_number' => 'receipt_number',
                'payment_method' => 'payment_method',
            ];
            $mappedHeaders = array_map(
                fn (string $header): ?string => $headerMap[$header] ?? null,
                $headers,
            );
            $mappedHeaders = array_values(array_filter($mappedHeaders));
            if (count($mappedHeaders) !== count(array_unique($mappedHeaders))) {
                throw ValidationException::withMessages(['file' => 'The CSV file contains duplicate column names.']);
            }

            $missing = array_diff(['source_transaction_id', 'nozzle_code', 'liters'], $mappedHeaders);
            if (! in_array('sold_at', $mappedHeaders, true)
                && (! in_array('date', $mappedHeaders, true) || ! in_array('time', $mappedHeaders, true))) {
                $missing[] = 'sold_at or both Date and Time';
            }
            if (in_array('date', $mappedHeaders, true) !== in_array('time', $mappedHeaders, true)) {
                $missing[] = 'both Date and Time';
            }
            if (in_array('price_per_liter', $mappedHeaders, true) !== in_array('total_amount', $mappedHeaders, true)) {
                $missing[] = 'both Price_Per_Liter and Total_Amount';
            }
            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'file' => 'The CSV file is missing required columns: '.implode(', ', $missing).'.',
                ]);
            }

            $rows = [];
            $rowNumber = 1;
            while (($values = fgetcsv($handle)) !== false) {
                $rowNumber++;
                if ($values === [null] || count($values) === 1 && trim((string) $values[0]) === '') {
                    continue;
                }
                if ($rowNumber > 5001) {
                    throw ValidationException::withMessages([
                        'file' => 'A single import is limited to 5,000 sales rows.',
                    ]);
                }

                $row = [];
                foreach ($headers as $index => $header) {
                    $key = $headerMap[$header] ?? null;
                    if ($key !== null) {
                        $row[$key] = isset($values[$index]) ? trim((string) $values[$index]) : null;
                    }
                }
                if (! array_key_exists('sold_at', $row)) {
                    $row['sold_at'] = trim(($row['date'] ?? '').' '.($row['time'] ?? ''));
                }
                $row['row_number'] = $rowNumber;
                $rows[] = $row;
            }

            if ($rows === []) {
                throw ValidationException::withMessages(['file' => 'The CSV file contains no sales rows.']);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function validateRows(int $branchId, array $rows): array
    {
        $branch = Branch::query()->findOrFail($branchId);
        $nozzleGroups = FuelNozzle::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->with(['pump', 'tank.fuelProduct'])
            ->get()
            ->groupBy('code');
        $transactionIds = collect($rows)->pluck('source_transaction_id')->filter()->unique()->values();
        $receipts = collect($rows)->pluck('receipt_number')->filter()->unique()->values();
        $existingIds = FuelSale::query()
            ->where('branch_id', $branchId)
            ->whereIn('source_system_transaction_id', $transactionIds)
            ->pluck('source_system_transaction_id')
            ->all();
        $existingReceipts = FuelSale::query()
            ->where('branch_id', $branchId)
            ->whereIn('receipt_number', $receipts)
            ->pluck('receipt_number')
            ->all();
        $seenIds = [];
        $seenReceipts = [];
        $normalized = [];
        $candidates = [];

        foreach ($rows as $row) {
            $values = [
                'source_transaction_id' => trim((string) ($row['source_transaction_id'] ?? '')),
                'sold_at' => trim((string) ($row['sold_at'] ?? '')),
                'nozzle_code' => trim((string) ($row['nozzle_code'] ?? '')),
                'branch_label' => trim((string) ($row['branch_label'] ?? '')) ?: null,
                'pump_code' => trim((string) ($row['pump_code'] ?? '')) ?: null,
                'fuel_grade' => trim((string) ($row['fuel_grade'] ?? '')) ?: null,
                'liters' => $row['liters'] ?? null,
                'price_per_liter' => trim((string) ($row['price_per_liter'] ?? '')) ?: null,
                'total_amount' => trim((string) ($row['total_amount'] ?? '')) ?: null,
                'receipt_number' => trim((string) ($row['receipt_number'] ?? '')) ?: null,
                'payment_method' => strtolower(trim((string) ($row['payment_method'] ?? ''))) ?: null,
            ];
            $hasSourcePrice = $values['price_per_liter'] !== null || $values['total_amount'] !== null;
            $validator = Validator::make($values, [
                'source_transaction_id' => ['required', 'string', 'max:120'],
                'sold_at' => ['required', 'date', 'before_or_equal:now'],
                'nozzle_code' => ['required', 'string', 'max:100'],
                'branch_label' => ['nullable', 'string', 'max:100'],
                'pump_code' => ['nullable', 'string', 'max:100'],
                'fuel_grade' => ['nullable', 'string', 'max:100'],
                'liters' => ['required', 'numeric', 'gt:0', 'max:100000'],
                'price_per_liter' => [$hasSourcePrice ? 'required' : 'nullable', 'numeric', 'gt:0', 'max:100000'],
                'total_amount' => [$hasSourcePrice ? 'required' : 'nullable', 'numeric', 'gt:0', 'max:100000000'],
                'receipt_number' => ['nullable', 'string', 'max:100'],
                'payment_method' => ['nullable', Rule::in(['cash', 'card', 'ewallet', 'other'])],
            ]);
            $errors = $validator->errors()->all();
            $rowNumber = (int) ($row['row_number'] ?? 0);

            if ($values['branch_label'] !== null && ! $this->matchesBranchLabel($branch, $values['branch_label'])) {
                $errors[] = 'This row belongs to a different branch. Import one branch per file.';
            }
            if ($hasSourcePrice && $validator->errors()->isEmpty()) {
                $expectedCents = (int) round((float) $values['liters'] * (float) $values['price_per_liter'] * 100);
                $providedCents = (int) round((float) $values['total_amount'] * 100);
                if ($expectedCents !== $providedCents) {
                    $errors[] = 'Total_Amount must equal Liters multiplied by Price_Per_Liter, rounded to centavos.';
                }
            }

            $transactionId = $values['source_transaction_id'];
            if (in_array($transactionId, $existingIds, true) || isset($seenIds[$transactionId])) {
                $errors[] = 'Transaction ID has already been imported or appears more than once in this file.';
            }
            if ($transactionId !== '') {
                $seenIds[$transactionId] = true;
            }
            $receipt = $values['receipt_number'];
            if ($receipt !== null && (in_array($receipt, $existingReceipts, true) || isset($seenReceipts[$receipt]))) {
                $errors[] = 'Receipt number has already been recorded for this branch.';
            }
            if ($receipt !== null) {
                $seenReceipts[$receipt] = true;
            }

            $nozzle = null;
            $matches = $nozzleGroups->get($values['nozzle_code']);
            if ($matches?->count() !== 1) {
                $errors[] = $matches?->isNotEmpty()
                    ? 'This nozzle code is ambiguous in the branch; use a unique nozzle code.'
                    : 'No active nozzle with this code exists in the branch.';
            } else {
                $nozzle = $matches->first();
                if (! $nozzle->pump->is_active || ! $nozzle->tank->is_active || ! $nozzle->tank->fuelProduct->is_active) {
                    $errors[] = 'The nozzle, pump, tank, and fuel product must all be active.';
                }
                if ($values['pump_code'] !== null
                    && strcasecmp(trim($nozzle->pump->code), $values['pump_code']) !== 0) {
                    $errors[] = 'Pump_ID does not match the pump configured for this nozzle.';
                }
                if ($values['fuel_grade'] !== null
                    && $this->fuelGradeKey($values['fuel_grade']) !== $this->fuelGradeKey($nozzle->tank->fuelProduct->name)) {
                    $errors[] = 'Fuel_Grade does not match the fuel configured for this nozzle.';
                }
                if ($validator->errors()->isEmpty()) {
                    if (! $hasSourcePrice) {
                        $priceExists = FuelPrice::query()
                            ->where('branch_id', $branchId)
                            ->where('fuel_product_id', $nozzle->tank->fuel_product_id)
                            ->where('effective_from', '<=', Carbon::parse($values['sold_at']))
                            ->exists();
                        if (! $priceExists) {
                            $errors[] = 'No fuel price was configured for the sale date.';
                        }
                    }
                }
            }

            $result = [
                'row_number' => $rowNumber,
                'source_transaction_id' => $transactionId,
                'branch_label' => $values['branch_label'],
                'pump_code' => $values['pump_code'],
                'fuel_grade' => $values['fuel_grade'],
                'sold_at' => $values['sold_at'],
                'nozzle_code' => $values['nozzle_code'],
                'liters' => is_numeric($values['liters']) ? $values['liters'] : 0,
                'price_per_liter' => is_numeric($values['price_per_liter']) ? $values['price_per_liter'] : null,
                'total_amount' => is_numeric($values['total_amount']) ? $values['total_amount'] : null,
                'receipt_number' => $receipt,
                'payment_method' => $values['payment_method'],
                'status' => $errors === [] ? 'valid' : 'invalid',
                'errors' => $errors === [] ? null : array_values(array_unique($errors)),
            ];
            $normalized[] = $result;
            if ($result['status'] === 'valid' && $nozzle !== null) {
                $candidates[] = [
                    'row_index' => count($normalized) - 1,
                    'row_number' => $rowNumber,
                    'tank_id' => (int) $nozzle->fuel_tank_id,
                    'sold_at' => Carbon::parse($values['sold_at']),
                    'liters' => (float) $values['liters'],
                ];
            }
        }

        $this->validateHistoricalStock($branchId, $candidates, $normalized);

        return $normalized;
    }

    private function matchesBranchLabel(Branch $branch, string $label): bool
    {
        $normalized = (string) preg_replace('/[^a-z0-9]/', '', strtolower($label));
        $aliases = match ($branch->code) {
            'manaoag' => ['manaoag', 'branch1', '1'],
            'san-fabian' => ['sanfabian', 'branch2', '2'],
            default => [
                (string) preg_replace('/[^a-z0-9]/', '', strtolower($branch->name)),
                (string) preg_replace('/[^a-z0-9]/', '', strtolower($branch->code)),
            ],
        };

        return in_array($normalized, $aliases, true);
    }

    private function fuelGradeKey(string $grade): ?string
    {
        $normalized = (string) preg_replace('/[^a-z0-9]/', '', strtolower($grade));

        if (str_contains($normalized, 'unleaded')
            || str_contains($normalized, 'greengas')
            || in_array($normalized, ['gas', 'gasoline'], true)) {
            return 'unleaded';
        }
        if (str_contains($normalized, 'premium')) {
            return 'premium';
        }
        if (str_contains($normalized, 'diesel')) {
            return 'diesel';
        }

        return null;
    }

    private function validateHistoricalStock(int $branchId, array $candidates, array &$rows): void
    {
        foreach (collect($candidates)->groupBy('tank_id') as $tankId => $tankCandidates) {
            $tank = FuelTank::query()->where('branch_id', $branchId)->whereKey($tankId)->firstOrFail();
            $events = $tank->movements()
                ->get(['id', 'occurred_at', 'signed_quantity_liters'])
                ->map(fn ($movement): array => [
                    'occurred_at' => $movement->occurred_at,
                    'order' => (int) $movement->id,
                    'change' => (float) $movement->signed_quantity_liters,
                    'row_index' => null,
                ])
                ->all();

            foreach ($tankCandidates as $candidate) {
                $events[] = [
                    'occurred_at' => $candidate['sold_at'],
                    'order' => PHP_INT_MAX,
                    'change' => -$candidate['liters'],
                    'row_index' => $candidate['row_index'],
                ];
            }

            usort($events, fn (array $left, array $right): int => $left['occurred_at']->getTimestamp() <=> $right['occurred_at']->getTimestamp()
                ?: $left['order'] <=> $right['order']);
            $balance = 0.0;
            foreach ($events as $event) {
                $balance += $event['change'];
                if ($event['row_index'] !== null
                    && ($balance < -0.0005 || $balance - (float) $tank->capacity_liters > 0.0005)) {
                    $row = &$rows[$event['row_index']];
                    $row['status'] = 'invalid';
                    $row['errors'] ??= [];
                    $row['errors'][] = $balance < -0.0005
                        ? 'This sale would make the tank balance negative at the recorded sale time.'
                        : 'This sale would make tank stock exceed capacity at the recorded sale time.';
                    $row['errors'] = array_values(array_unique($row['errors']));
                    unset($row);
                }
            }
        }
    }

    private function batchSummary(SalesImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'branch_id' => $batch->branch_id,
            'status' => $batch->status,
            'filename' => $batch->original_filename,
            'row_count' => $batch->row_count,
            'valid_row_count' => $batch->valid_row_count,
            'invalid_row_count' => $batch->invalid_row_count,
            'imported_at' => $batch->imported_at,
            'created_at' => $batch->created_at,
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
}

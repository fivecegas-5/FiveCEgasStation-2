<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\FuelSale;
use App\Models\FuelTank;
use App\Models\InventoryMovement;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function sales(Request $request)
    {
        [$branchId, $from, $to] = $this->filters($request);
        $pageSize = $request->validate(['per_page' => ['nullable', 'integer', 'between:1,100']])['per_page'] ?? 25;

        return $this->salesQuery($request, $branchId, $from, $to)
            ->with(['branch', 'fuelProduct', 'nozzle.pump', 'recorder', 'shift'])
            ->orderByDesc('sold_at')
            ->paginate($pageSize);
    }

    public function inventoryMovements(Request $request)
    {
        [$branchId, $from, $to] = $this->filters($request);
        $pageSize = $request->validate(['per_page' => ['nullable', 'integer', 'between:1,100']])['per_page'] ?? 25;

        return InventoryMovement::query()
            ->with(['branch', 'tank.fuelProduct', 'recorder', 'source'])
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($branchId === null && $request->user()->role !== UserRole::Owner, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->orderByDesc('occurred_at')
            ->paginate($pageSize);
    }

    public function summary(Request $request)
    {
        [$branchId, $from, $to] = $this->filters($request);
        $sales = $this->salesQuery($request, $branchId, $from, $to);
        $base = clone $sales;
        $knownCostSales = (clone $sales)
            ->whereHas('inventoryMovements', fn (Builder $query) => $query
                ->where('movement_type', 'sale')
                ->whereNotNull('unit_cost_per_liter'))
            ->withMin(['inventoryMovements as sale_unit_cost' => fn (Builder $query) => $query
                ->where('movement_type', 'sale')
                ->whereNotNull('unit_cost_per_liter')], 'unit_cost_per_liter')
            ->get();

        $knownRevenue = (float) $knownCostSales->sum('total_amount');
        $costOfGoodsSold = (float) $knownCostSales->sum(
            fn (FuelSale $sale): float => (float) $sale->liters * (float) $sale->sale_unit_cost,
        );
        $totalLiters = (float) (clone $base)->sum('liters');
        $knownCostLiters = (float) (clone $base)
            ->whereHas('inventoryMovements', fn (Builder $query) => $query
                ->where('movement_type', 'sale')
                ->whereNotNull('unit_cost_per_liter'))
            ->sum('liters');

        $branchScope = InventoryMovement::query();
        if ($branchId !== null) {
            $branchScope->where('branch_id', $branchId);
        } elseif ($request->user()->role !== UserRole::Owner) {
            $branchScope->whereRaw('1 = 0');
        }
        $branchScope->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        $balances = FuelTank::query()
            ->with('branch', 'fuelProduct')
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($branchId === null && $request->user()->role === UserRole::Owner, fn (Builder $query) => $query->whereHas('branch', fn (Builder $branch) => $branch->where('is_active', true)))
            ->get()
            ->map(function (FuelTank $tank): array {
                return [
                    'branch' => $tank->branch->name,
                    'branch_id' => $tank->branch_id,
                    'tank_code' => $tank->code,
                    'fuel' => $tank->fuelProduct->name,
                    'capacity_liters' => (float) $tank->capacity_liters,
                    'stock_liters' => (float) $tank->movements()->sum('signed_quantity_liters'),
                ];
            });

        $salesByProduct = (clone $base)
            ->select(
                'fuel_product_id',
                DB::raw('SUM(liters) as liters'),
                DB::raw('SUM(total_amount) as sales_amount'),
                DB::raw('COUNT(*) as transaction_count'),
            )
            ->with('fuelProduct')
            ->groupBy('fuel_product_id')
            ->get()
            ->map(fn (FuelSale $sale): array => [
                'fuel_product_id' => $sale->fuel_product_id,
                'fuel' => $sale->fuelProduct->name,
                'liters' => (float) $sale->liters,
                'sales_amount' => (float) $sale->sales_amount,
                'transaction_count' => (int) $sale->transaction_count,
            ]);

        $salesByDayAndFuel = (clone $base)
            ->join('fuel_products', 'fuel_sales.fuel_product_id', '=', 'fuel_products.id')
            ->select(
                'fuel_sales.fuel_product_id',
                'fuel_products.name as fuel',
                DB::raw('DATE(fuel_sales.sold_at) as date'),
                DB::raw('SUM(fuel_sales.liters) as liters'),
                DB::raw('SUM(fuel_sales.total_amount) as revenue'),
                DB::raw('COUNT(*) as transaction_count'),
            )
            ->groupBy('fuel_sales.fuel_product_id', 'fuel_products.name', DB::raw('DATE(fuel_sales.sold_at)'))
            ->orderBy(DB::raw('DATE(fuel_sales.sold_at)'))
            ->get()
            ->map(fn ($day): array => [
                'fuel_product_id' => (int) $day->fuel_product_id,
                'fuel' => $day->fuel,
                'date' => $day->date,
                'liters' => (float) $day->liters,
                'revenue' => (float) $day->revenue,
                'transaction_count' => (int) $day->transaction_count,
            ]);

        $salesByDay = (clone $base)
            ->join('branches', 'fuel_sales.branch_id', '=', 'branches.id')
            ->select(
                'fuel_sales.branch_id',
                'branches.name as branch',
                DB::raw('DATE(fuel_sales.sold_at) as date'),
                DB::raw('SUM(fuel_sales.total_amount) as revenue'),
                DB::raw('SUM(fuel_sales.liters) as liters'),
            )
            ->groupBy('fuel_sales.branch_id', 'branches.name', DB::raw('DATE(fuel_sales.sold_at)'))
            ->orderBy(DB::raw('DATE(fuel_sales.sold_at)'))
            ->get()
            ->map(fn ($day): array => [
                'branch_id' => (int) $day->branch_id,
                'branch' => $day->branch,
                'date' => $day->date,
                'revenue' => (float) $day->revenue,
                'liters' => (float) $day->liters,
            ]);

        $shifts = Shift::query()
            ->with('branch')
            ->where('status', 'closed')
            ->whereBetween('closed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->get()
            ->map(fn (Shift $shift): array => [
                'shift_id' => $shift->id,
                'branch' => $shift->branch->name,
                'opened_at' => $shift->opened_at,
                'closed_at' => $shift->closed_at,
                'closing_summary' => $shift->closing_summary ?? [],
            ]);

        return response()->json([
            'filters' => ['branch_id' => $branchId, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'sales' => [
                'transaction_count' => (clone $base)->count(),
                'liters' => $totalLiters,
                'revenue' => (float) (clone $base)->sum('total_amount'),
                'costed_liters' => $knownCostLiters,
                'unvalued_liters' => max(0, $totalLiters - $knownCostLiters),
                'cost_coverage_percent' => $totalLiters > 0 ? round($knownCostLiters / $totalLiters * 100, 2) : null,
                'costed_revenue' => $knownRevenue,
                'cost_of_goods_sold' => $costOfGoodsSold,
                'estimated_gross_profit' => round($knownRevenue - $costOfGoodsSold, 2),
                'profit_is_partial' => $knownCostLiters + 0.0005 < $totalLiters,
            ],
            'sales_by_fuel' => $salesByProduct,
            'sales_by_day_and_fuel' => $salesByDayAndFuel,
            'sales_by_day' => $salesByDay,
            'inventory_balances' => $balances,
            'movement_totals' => $branchScope
                ->select('movement_type', DB::raw('SUM(signed_quantity_liters) as net_liters'), DB::raw('COUNT(*) as movement_count'))
                ->groupBy('movement_type')
                ->get(),
            'closed_shifts' => $shifts,
        ]);
    }

    public function salesCsv(Request $request): StreamedResponse
    {
        [$branchId, $from, $to] = $this->filters($request);
        $sales = $this->salesQuery($request, $branchId, $from, $to)
            ->with(['branch', 'fuelProduct', 'nozzle', 'recorder'])
            ->orderBy('sold_at');
        $filename = 'fivecetrack-sales-'.$from->toDateString().'-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($sales): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Branch', 'Date', 'Receipt', 'Fuel', 'Nozzle', 'Liters', 'Price per liter', 'Sales amount', 'Recorded by']);

            foreach ($sales->lazy(500) as $sale) {
                fputcsv($output, [
                    $this->safeCsvText($sale->branch->name),
                    $sale->sold_at->toIso8601String(),
                    $this->safeCsvText($sale->receipt_number),
                    $this->safeCsvText($sale->fuelProduct->name),
                    $this->safeCsvText($sale->nozzle->code),
                    $sale->liters,
                    $sale->price_per_liter,
                    $sale->total_amount,
                    $this->safeCsvText($sale->recorder->name),
                ]);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function monthlySalesCsv(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);
        $month = Carbon::createFromFormat('!Y-m', $data['month']);
        $request->merge([
            'from' => $month->toDateString(),
            'to' => $month->copy()->endOfMonth()->toDateString(),
        ]);
        [$branchId, $from, $to] = $this->filters($request);
        $sales = FuelSale::query()
            ->whereIn('status', ['completed', 'voided'])
            ->whereBetween('sold_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($branchId === null && $request->user()->role !== UserRole::Owner, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->with(['branch', 'fuelProduct', 'nozzle', 'recorder'])
            ->orderBy('sold_at');
        $filename = 'fivecetrack-monthly-sales-'.$data['month'].'.csv';

        return response()->streamDownload(function () use ($sales): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'Branch',
                'Date',
                'Receipt',
                'Fuel',
                'Nozzle',
                'Liters',
                'Price per liter',
                'Sales amount',
                'Payment method',
                'Status',
                'Voided at',
                'Void reason',
                'Recorded by',
            ]);

            foreach ($sales->lazy(500) as $sale) {
                fputcsv($output, [
                    $this->safeCsvText($sale->branch->name),
                    $sale->sold_at->toIso8601String(),
                    $this->safeCsvText($sale->receipt_number),
                    $this->safeCsvText($sale->fuelProduct->name),
                    $this->safeCsvText($sale->nozzle->code),
                    $sale->liters,
                    $sale->price_per_liter,
                    $sale->total_amount,
                    $this->safeCsvText($sale->payment_method),
                    $sale->status,
                    $sale->voided_at?->toIso8601String(),
                    $this->safeCsvText($sale->void_reason),
                    $this->safeCsvText($sale->recorder->name),
                ]);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function auditLogs(Request $request)
    {
        [$branchId, $from, $to] = $this->filters($request);
        $pageSize = $request->validate(['per_page' => ['nullable', 'integer', 'between:1,100']])['per_page'] ?? 25;

        return AuditLog::query()
            ->with(['branch', 'user'])
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($branchId === null && $request->user()->role !== UserRole::Owner, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->orderByDesc('occurred_at')
            ->paginate($pageSize);
    }

    public function lowStockAlerts(Request $request)
    {
        [$branchId] = $this->filters($request);
        $tanks = FuelTank::query()
            ->with(['branch', 'fuelProduct'])
            ->where('is_active', true)
            ->whereNotNull('low_stock_threshold_liters')
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when(
                $branchId === null && $request->user()->role === UserRole::Owner,
                fn (Builder $query) => $query->whereHas('branch', fn (Builder $branch) => $branch->where('is_active', true)),
            )
            ->get()
            ->map(function (FuelTank $tank): ?array {
                $stock = (float) $tank->movements()->sum('signed_quantity_liters');
                $threshold = (float) $tank->low_stock_threshold_liters;
                if ($stock > $threshold) {
                    return null;
                }

                return [
                    'branch_id' => $tank->branch_id,
                    'branch' => $tank->branch->name,
                    'tank_id' => $tank->id,
                    'tank_code' => $tank->code,
                    'fuel' => $tank->fuelProduct->name,
                    'stock_liters' => round($stock, 3),
                    'threshold_liters' => round($threshold, 3),
                ];
            })
            ->filter()
            ->values();

        return response()->json(['data' => $tanks]);
    }

    private function filters(Request $request): array
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if (isset($data['from'], $data['to']) && $data['to'] < $data['from']) {
            throw ValidationException::withMessages([
                'to' => 'The report end date must be on or after its start date.',
            ]);
        }
        $user = $request->user();
        abort_unless($user->isOwner(), 403);
        if ($user->branch_id !== null) {
            $branchId = $user->branch_id;
            abort_unless(! isset($data['branch_id']) || (int) $data['branch_id'] === $branchId, 404);
        } else {
            $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
            abort_unless($branchId === null || $user->canAccessBranch($branchId), 404);
        }

        $from = isset($data['from']) ? now()->parse($data['from']) : now()->startOfMonth();
        $to = isset($data['to']) ? now()->parse($data['to']) : now();
        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages([
                'to' => 'Report date ranges cannot exceed 366 days.',
            ]);
        }

        return [$branchId, $from, $to];
    }

    private function salesQuery(Request $request, ?int $branchId, $from, $to): Builder
    {
        return FuelSale::query()
            ->where('status', 'completed')
            ->whereBetween('sold_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($branchId === null && $request->user()->role !== UserRole::Owner, fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    private function safeCsvText(?string $value): string
    {
        if ($value !== null && preg_match('/^[\s]*[=+\-@]/u', $value) === 1) {
            return "'".$value;
        }

        return $value ?? '';
    }
}

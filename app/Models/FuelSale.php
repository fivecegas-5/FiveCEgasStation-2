<?php

namespace App\Models;

use App\Models\Concerns\ScopesToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class FuelSale extends Model
{
    use HasFactory, ScopesToBranch;

    protected $fillable = [
        'branch_id', 'fuel_nozzle_id', 'fuel_product_id', 'shift_id', 'fuel_price_id',
        'receipt_number', 'liters', 'price_per_liter', 'total_amount', 'payment_method',
        'sold_at', 'recorded_by', 'status', 'voided_by', 'voided_at', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'liters' => 'decimal:3',
            'price_per_liter' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'sold_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function nozzle(): BelongsTo
    {
        return $this->belongsTo(FuelNozzle::class, 'fuel_nozzle_id');
    }

    public function fuelProduct(): BelongsTo
    {
        return $this->belongsTo(FuelProduct::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function fuelPrice(): BelongsTo
    {
        return $this->belongsTo(FuelPrice::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function inventoryMovements(): MorphMany
    {
        return $this->morphMany(InventoryMovement::class, 'source');
    }
}

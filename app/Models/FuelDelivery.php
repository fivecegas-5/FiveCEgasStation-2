<?php

namespace App\Models;

use App\Models\Concerns\ScopesToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class FuelDelivery extends Model
{
    use HasFactory, ScopesToBranch;

    protected $fillable = [
        'branch_id', 'fuel_tank_id', 'supplier_name', 'delivery_reference', 'delivered_liters',
        'cost_per_liter', 'total_cost', 'received_at', 'recorded_by', 'status', 'reviewed_by',
        'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'delivered_liters' => 'decimal:3',
            'cost_per_liter' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'received_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(FuelTank::class, 'fuel_tank_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function inventoryMovements(): MorphMany
    {
        return $this->morphMany(InventoryMovement::class, 'source');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\ScopesToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryMovement extends Model
{
    use HasFactory, ScopesToBranch;

    protected $fillable = [
        'branch_id', 'fuel_tank_id', 'movement_type', 'signed_quantity_liters',
        'unit_cost_per_liter', 'recorded_by', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'signed_quantity_liters' => 'decimal:3',
            'unit_cost_per_liter' => 'decimal:2',
            'occurred_at' => 'datetime',
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

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\ScopesToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class FuelTank extends Model
{
    use HasFactory, ScopesToBranch;

    protected $fillable = ['branch_id', 'fuel_product_id', 'code', 'capacity_liters', 'is_active'];

    protected function casts(): array
    {
        return ['capacity_liters' => 'decimal:3', 'is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fuelProduct(): BelongsTo
    {
        return $this->belongsTo(FuelProduct::class);
    }

    public function nozzles(): HasMany
    {
        return $this->hasMany(FuelNozzle::class, 'fuel_tank_id');
    }

    public function calibrationPoints(): HasMany
    {
        return $this->hasMany(TankCalibrationPoint::class, 'fuel_tank_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(FuelDelivery::class, 'fuel_tank_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'fuel_tank_id');
    }

    public function inventoryMovements(): MorphMany
    {
        return $this->morphMany(InventoryMovement::class, 'source');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\ScopesToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FuelNozzle extends Model
{
    use HasFactory, ScopesToBranch;

    protected $fillable = ['branch_id', 'fuel_pump_id', 'fuel_tank_id', 'code', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function pump(): BelongsTo
    {
        return $this->belongsTo(FuelPump::class, 'fuel_pump_id');
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(FuelTank::class, 'fuel_tank_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(FuelSale::class, 'fuel_nozzle_id');
    }
}

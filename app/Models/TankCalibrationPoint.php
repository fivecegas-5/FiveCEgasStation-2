<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TankCalibrationPoint extends Model
{
    use HasFactory;

    protected $fillable = ['fuel_tank_id', 'dip_cm', 'volume_liters'];

    protected function casts(): array
    {
        return ['dip_cm' => 'decimal:2', 'volume_liters' => 'decimal:3'];
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(FuelTank::class, 'fuel_tank_id');
    }
}

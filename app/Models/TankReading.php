<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TankReading extends Model
{
    use HasFactory;

    protected $fillable = ['shift_id', 'fuel_tank_id', 'reading_type', 'dip_cm', 'measured_liters', 'recorded_by', 'recorded_at'];

    protected function casts(): array
    {
        return ['dip_cm' => 'decimal:2', 'measured_liters' => 'decimal:3', 'recorded_at' => 'datetime'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(FuelTank::class, 'fuel_tank_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

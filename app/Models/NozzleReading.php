<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NozzleReading extends Model
{
    use HasFactory;

    protected $fillable = ['shift_id', 'fuel_nozzle_id', 'reading_type', 'meter_reading', 'recorded_by', 'recorded_at'];

    protected function casts(): array
    {
        return ['meter_reading' => 'decimal:3', 'recorded_at' => 'datetime'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function nozzle(): BelongsTo
    {
        return $this->belongsTo(FuelNozzle::class, 'fuel_nozzle_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

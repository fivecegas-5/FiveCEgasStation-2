<?php

namespace App\Models;

use App\Models\Concerns\ScopesToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use HasFactory, ScopesToBranch;

    protected $fillable = [
        'branch_id', 'opened_by', 'closed_by', 'opened_at', 'closed_at',
        'status', 'notes', 'closing_summary',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'closing_summary' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function tankReadings(): HasMany
    {
        return $this->hasMany(TankReading::class);
    }

    public function nozzleReadings(): HasMany
    {
        return $this->hasMany(NozzleReading::class);
    }

    public function fuelSales(): HasMany
    {
        return $this->hasMany(FuelSale::class);
    }
}

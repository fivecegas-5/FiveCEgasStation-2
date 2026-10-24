<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FuelProduct extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'unit', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function fuelTanks(): HasMany
    {
        return $this->hasMany(FuelTank::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(FuelPrice::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(FuelSale::class);
    }
}

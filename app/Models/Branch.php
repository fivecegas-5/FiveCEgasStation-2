<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'address', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === UserRole::Owner) {
            return $query;
        }

        return $user->branch_id === null
            ? $query->whereRaw('1 = 0')
            : $query->whereKey($user->branch_id);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function fuelTanks(): HasMany
    {
        return $this->hasMany(FuelTank::class);
    }

    public function fuelPumps(): HasMany
    {
        return $this->hasMany(FuelPump::class);
    }

    public function fuelPrices(): HasMany
    {
        return $this->hasMany(FuelPrice::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function fuelDeliveries(): HasMany
    {
        return $this->hasMany(FuelDelivery::class);
    }

    public function fuelSales(): HasMany
    {
        return $this->hasMany(FuelSale::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}

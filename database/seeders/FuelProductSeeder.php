<?php

namespace Database\Seeders;

use App\Models\FuelProduct;
use Illuminate\Database\Seeder;

class FuelProductSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Unleaded', 'Premium', 'Diesel'] as $name) {
            FuelProduct::firstOrCreate(
                ['name' => $name],
                ['unit' => 'liter', 'is_active' => true],
            );
        }
    }
}

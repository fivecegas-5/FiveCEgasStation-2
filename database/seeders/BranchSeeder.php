<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::updateOrCreate(
            ['code' => 'manaoag'],
            ['name' => 'Manaoag', 'address' => 'Cabanbanan, Manaoag, Pangasinan', 'is_active' => true],
        );

        Branch::updateOrCreate(
            ['code' => 'san-fabian'],
            ['name' => 'San Fabian', 'address' => 'Bolasi, San Fabian, Pangasinan', 'is_active' => true],
        );
    }
}

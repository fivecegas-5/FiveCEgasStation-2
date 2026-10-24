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
            ['name' => 'Manaoag', 'address' => 'Manaoag, Pangasinan', 'is_active' => true],
        );

        Branch::updateOrCreate(
            ['code' => 'san-fabian'],
            ['name' => 'San Fabian', 'address' => 'San Fabian, Pangasinan', 'is_active' => true],
        );
    }
}

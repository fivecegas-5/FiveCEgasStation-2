<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OWNER_EMAIL = 'fivecegas8@gmail.com';

    public function up(): void
    {
        DB::transaction(function (): void {
            $designatedOwner = DB::table('users')->where('email', self::OWNER_EMAIL)->first(['id']);

            DB::table('users')
                ->whereIn('role', ['owner', 'manager'])
                ->when($designatedOwner !== null, fn ($query) => $query->where('id', '!=', $designatedOwner->id))
                ->update(['role' => 'staff', 'is_active' => false]);

            if ($designatedOwner !== null) {
                DB::table('users')
                    ->where('id', $designatedOwner->id)
                    ->update(['role' => 'owner', 'branch_id' => null]);
            }
        });
    }

    public function down(): void
    {
        // Historical owner and manager assignments cannot be reconstructed safely.
    }
};

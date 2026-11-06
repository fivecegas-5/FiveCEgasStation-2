<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->decimal('unit_cost_per_liter', 12, 2)->nullable();
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->json('closing_summary')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('closing_summary');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn('unit_cost_per_liter');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};

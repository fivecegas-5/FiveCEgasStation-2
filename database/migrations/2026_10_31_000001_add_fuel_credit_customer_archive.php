<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('fuel_credit_customers', 'is_active')) {
            Schema::table('fuel_credit_customers', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fuel_credit_customers', 'is_active')) {
            Schema::table('fuel_credit_customers', function (Blueprint $table): void {
                $table->dropColumn('is_active');
            });
        }
    }
};

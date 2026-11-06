<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_pumps', function (Blueprint $table): void {
            $table->string('label')->nullable()->after('code');
        });

        Schema::table('sales_import_rows', function (Blueprint $table): void {
            $table->string('branch_label')->nullable()->after('source_transaction_id');
            $table->string('pump_code')->nullable()->after('branch_label');
            $table->string('fuel_grade')->nullable()->after('pump_code');
            $table->decimal('price_per_liter', 12, 2)->nullable()->after('liters');
            $table->decimal('total_amount', 14, 2)->nullable()->after('price_per_liter');
        });
    }

    public function down(): void
    {
        Schema::table('sales_import_rows', function (Blueprint $table): void {
            $table->dropColumn([
                'branch_label',
                'pump_code',
                'fuel_grade',
                'price_per_liter',
                'total_amount',
            ]);
        });

        Schema::table('fuel_pumps', function (Blueprint $table): void {
            $table->dropColumn('label');
        });
    }
};

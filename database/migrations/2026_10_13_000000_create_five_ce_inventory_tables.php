<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
        });

        Schema::create('fuel_products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('unit')->default('liter');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fuel_tanks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_product_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->decimal('capacity_liters', 12, 3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('tank_calibration_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fuel_tank_id')->constrained()->restrictOnDelete();
            $table->decimal('dip_cm', 10, 2);
            $table->decimal('volume_liters', 12, 3);
            $table->timestamps();
            $table->unique(['fuel_tank_id', 'dip_cm']);
        });

        Schema::create('fuel_pumps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('fuel_nozzles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_pump_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_tank_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['fuel_pump_id', 'code']);
            $table->index(['branch_id', 'fuel_tank_id']);
        });

        Schema::create('fuel_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_product_id')->constrained()->restrictOnDelete();
            $table->decimal('price_per_liter', 12, 2);
            $table->timestamp('effective_from');
            $table->foreignId('set_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'fuel_product_id', 'effective_from'], 'fuel_prices_effective_unique');
            $table->index(['branch_id', 'fuel_product_id', 'effective_from']);
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('status')->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status', 'opened_at']);
        });

        Schema::create('tank_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_tank_id')->constrained()->restrictOnDelete();
            $table->string('reading_type');
            $table->decimal('dip_cm', 10, 2)->nullable();
            $table->decimal('measured_liters', 12, 3);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->unique(['shift_id', 'fuel_tank_id', 'reading_type'], 'tank_readings_shift_tank_type_unique');
        });

        Schema::create('nozzle_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_nozzle_id')->constrained()->restrictOnDelete();
            $table->string('reading_type');
            $table->decimal('meter_reading', 14, 3);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->unique(['shift_id', 'fuel_nozzle_id', 'reading_type'], 'nozzle_readings_shift_nozzle_type_unique');
        });

        Schema::create('fuel_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_tank_id')->constrained()->restrictOnDelete();
            $table->string('supplier_name')->nullable();
            $table->string('delivery_reference')->nullable();
            $table->decimal('delivered_liters', 12, 3);
            $table->decimal('cost_per_liter', 12, 2);
            $table->decimal('total_cost', 14, 2);
            $table->timestamp('received_at');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status', 'received_at']);
        });

        Schema::create('fuel_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_nozzle_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_product_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('fuel_price_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('receipt_number')->nullable();
            $table->decimal('liters', 12, 3);
            $table->decimal('price_per_liter', 12, 2);
            $table->decimal('total_amount', 14, 2);
            $table->string('payment_method')->nullable();
            $table->timestamp('sold_at');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('completed');
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'receipt_number'], 'fuel_sales_branch_receipt_unique');
            $table->index(['branch_id', 'sold_at']);
            $table->index(['branch_id', 'fuel_product_id', 'sold_at']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_tank_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_change_liters', 12, 3);
            $table->text('reason');
            $table->string('status')->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_tank_id')->constrained()->restrictOnDelete();
            $table->string('movement_type');
            $table->decimal('signed_quantity_liters', 12, 3);
            $table->morphs('source');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['branch_id', 'fuel_tank_id', 'occurred_at'], 'inventory_movements_stock_history');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->nullableMorphs('subject');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['branch_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('fuel_sales');
        Schema::dropIfExists('fuel_deliveries');
        Schema::dropIfExists('nozzle_readings');
        Schema::dropIfExists('tank_readings');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('fuel_prices');
        Schema::dropIfExists('fuel_nozzles');
        Schema::dropIfExists('fuel_pumps');
        Schema::dropIfExists('tank_calibration_points');
        Schema::dropIfExists('fuel_tanks');
        Schema::dropIfExists('fuel_products');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
        Schema::dropIfExists('branches');
    }
};

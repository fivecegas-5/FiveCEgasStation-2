<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_deliveries', function (Blueprint $table): void {
            if (! Schema::hasColumn('fuel_deliveries', 'invoice_path')) {
                $table->string('invoice_path')->nullable();
            }
            if (! Schema::hasColumn('fuel_deliveries', 'invoice_original_name')) {
                $table->string('invoice_original_name')->nullable();
            }
            if (! Schema::hasColumn('fuel_deliveries', 'invoice_mime_type')) {
                $table->string('invoice_mime_type')->nullable();
            }
            if (! Schema::hasColumn('fuel_deliveries', 'invoice_size_bytes')) {
                $table->unsignedBigInteger('invoice_size_bytes')->nullable();
            }
        });

        Schema::table('fuel_tanks', function (Blueprint $table): void {
            if (! Schema::hasColumn('fuel_tanks', 'low_stock_threshold_liters')) {
                $table->decimal('low_stock_threshold_liters', 12, 3)->nullable();
            }
        });

        if (! Schema::hasTable('fuel_credit_customers')) {
            Schema::create('fuel_credit_customers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->string('name');
                $table->string('phone', 40)->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->index(['branch_id', 'name']);
            });
        }

        if (! Schema::hasTable('fuel_credit_agreements')) {
            Schema::create('fuel_credit_agreements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->foreignId('fuel_credit_customer_id')->constrained()->restrictOnDelete();
                $table->foreignId('fuel_sale_id')->unique()->constrained()->restrictOnDelete();
                $table->foreignId('fuel_nozzle_id')->constrained()->restrictOnDelete();
                $table->foreignId('fuel_product_id')->constrained()->restrictOnDelete();
                $table->string('agreement_reference')->nullable();
                $table->decimal('liters', 12, 3);
                $table->decimal('price_per_liter', 12, 2);
                $table->decimal('principal_amount', 14, 2);
                $table->decimal('monthly_payment_amount', 14, 2);
                $table->date('first_due_date');
                $table->string('status')->default('active');
                $table->string('agreement_file_path')->nullable();
                $table->string('agreement_original_name')->nullable();
                $table->string('agreement_mime_type')->nullable();
                $table->unsignedBigInteger('agreement_size_bytes')->nullable();
                $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->unique(['branch_id', 'agreement_reference'], 'fuel_credit_agreements_branch_reference_unique');
                $table->index(['branch_id', 'status', 'first_due_date']);
            });
        }

        if (! Schema::hasTable('fuel_credit_installments')) {
            Schema::create('fuel_credit_installments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('fuel_credit_agreement_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('installment_number');
                $table->date('due_date');
                $table->decimal('amount_due', 14, 2);
                $table->decimal('amount_paid', 14, 2)->default(0);
                $table->string('status')->default('pending');
                $table->timestamps();
                $table->unique(['fuel_credit_agreement_id', 'installment_number'], 'fuel_credit_installments_agreement_number_unique');
                $table->index(['due_date', 'status']);
            });
        }

        if (! Schema::hasTable('fuel_credit_payments')) {
            Schema::create('fuel_credit_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('fuel_credit_agreement_id')->constrained()->restrictOnDelete();
                $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
                $table->decimal('amount', 14, 2);
                $table->timestamp('paid_at');
                $table->string('payment_method')->nullable();
                $table->string('reference')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['fuel_credit_agreement_id', 'paid_at']);
            });
        }

        if (! Schema::hasTable('fuel_credit_payment_allocations')) {
            Schema::create('fuel_credit_payment_allocations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('fuel_credit_payment_id')->constrained()->restrictOnDelete();
                $table->foreignId('fuel_credit_installment_id')->constrained()->restrictOnDelete();
                $table->decimal('amount', 14, 2);
                $table->timestamps();
                $table->unique(
                    ['fuel_credit_payment_id', 'fuel_credit_installment_id'],
                    'fuel_credit_payment_installment_unique',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_credit_payment_allocations');
        Schema::dropIfExists('fuel_credit_payments');
        Schema::dropIfExists('fuel_credit_installments');
        Schema::dropIfExists('fuel_credit_agreements');
        Schema::dropIfExists('fuel_credit_customers');

        Schema::table('fuel_tanks', function (Blueprint $table) {
            $table->dropColumn('low_stock_threshold_liters');
        });

        Schema::table('fuel_deliveries', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_path',
                'invoice_original_name',
                'invoice_mime_type',
                'invoice_size_bytes',
            ]);
        });
    }
};

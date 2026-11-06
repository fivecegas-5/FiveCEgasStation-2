<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('content_sha256', 64);
            $table->string('status')->default('previewed');
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('valid_row_count');
            $table->unsignedInteger('invalid_row_count');
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'content_sha256'], 'sales_import_branch_hash_unique');
            $table->index(['branch_id', 'status', 'created_at']);
        });

        Schema::table('fuel_sales', function (Blueprint $table) {
            $table->foreignId('sales_import_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_system_transaction_id')->nullable();
            $table->unique(
                ['branch_id', 'source_system_transaction_id'],
                'fuel_sales_branch_source_transaction_unique',
            );
        });

        Schema::create('sales_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_import_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('source_transaction_id');
            $table->timestamp('sold_at');
            $table->string('nozzle_code', 100);
            $table->decimal('liters', 12, 3);
            $table->string('receipt_number')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('status')->default('valid');
            $table->json('errors')->nullable();
            $table->foreignId('fuel_sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['sales_import_batch_id', 'row_number'], 'sales_import_rows_batch_row_unique');
            $table->index(
                ['sales_import_batch_id', 'source_transaction_id'],
                'sales_import_rows_batch_source_index',
            );
            $table->index(['sales_import_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_import_rows');

        Schema::table('fuel_sales', function (Blueprint $table) {
            $table->dropUnique('fuel_sales_branch_source_transaction_unique');
            $table->dropConstrainedForeignId('sales_import_batch_id');
            $table->dropColumn('source_system_transaction_id');
        });

        Schema::dropIfExists('sales_import_batches');
    }
};

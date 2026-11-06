<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_credit_payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('fuel_credit_payments', 'status')) {
                $table->string('status')->default('posted');
            }
            if (! Schema::hasColumn('fuel_credit_payments', 'voided_by')) {
                $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            }
            if (! Schema::hasColumn('fuel_credit_payments', 'voided_at')) {
                $table->timestamp('voided_at')->nullable();
            }
            if (! Schema::hasColumn('fuel_credit_payments', 'void_reason')) {
                $table->text('void_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fuel_credit_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['status', 'voided_at', 'void_reason']);
        });
    }
};

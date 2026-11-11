<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_credit_customers', function (Blueprint $table): void {
            $table->string('email')->nullable()->after('name');
            $table->unique(['branch_id', 'email'], 'fuel_credit_customers_branch_email_unique');
        });
    }

    public function down(): void
    {
        Schema::table('fuel_credit_customers', function (Blueprint $table): void {
            $table->dropUnique('fuel_credit_customers_branch_email_unique');
            $table->dropColumn('email');
        });
    }
};

<?php

namespace App\Models;

use App\Models\Concerns\ScopesToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FuelCreditAgreement extends Model
{
    use HasFactory, ScopesToBranch;

    protected $fillable = [
        'branch_id',
        'fuel_credit_customer_id',
        'fuel_sale_id',
        'fuel_nozzle_id',
        'fuel_product_id',
        'agreement_reference',
        'liters',
        'price_per_liter',
        'principal_amount',
        'monthly_payment_amount',
        'first_due_date',
        'status',
        'agreement_file_path',
        'agreement_original_name',
        'agreement_mime_type',
        'agreement_size_bytes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'liters' => 'decimal:3',
            'price_per_liter' => 'decimal:2',
            'principal_amount' => 'decimal:2',
            'monthly_payment_amount' => 'decimal:2',
            'first_due_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(FuelCreditCustomer::class, 'fuel_credit_customer_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(FuelSale::class, 'fuel_sale_id');
    }

    public function nozzle(): BelongsTo
    {
        return $this->belongsTo(FuelNozzle::class, 'fuel_nozzle_id');
    }

    public function fuelProduct(): BelongsTo
    {
        return $this->belongsTo(FuelProduct::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(FuelCreditInstallment::class);
    }

    public function nextPendingInstallment(): HasOne
    {
        return $this->hasOne(FuelCreditInstallment::class)
            ->where('status', '!=', 'paid')
            ->oldest('due_date')
            ->oldest('installment_number');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FuelCreditPayment::class);
    }
}

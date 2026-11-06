<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FuelCreditInstallment extends Model
{
    use HasFactory;

    protected $fillable = [
        'fuel_credit_agreement_id',
        'installment_number',
        'due_date',
        'amount_due',
        'amount_paid',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount_due' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(FuelCreditAgreement::class, 'fuel_credit_agreement_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(FuelCreditPaymentAllocation::class, 'fuel_credit_installment_id');
    }
}

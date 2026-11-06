<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuelCreditPaymentAllocation extends Model
{
    use HasFactory;

    protected $fillable = ['fuel_credit_payment_id', 'fuel_credit_installment_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FuelCreditPayment::class, 'fuel_credit_payment_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(FuelCreditInstallment::class, 'fuel_credit_installment_id');
    }
}

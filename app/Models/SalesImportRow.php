<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesImportRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_import_batch_id',
        'row_number',
        'source_transaction_id',
        'branch_label',
        'pump_code',
        'fuel_grade',
        'sold_at',
        'nozzle_code',
        'liters',
        'price_per_liter',
        'total_amount',
        'receipt_number',
        'payment_method',
        'status',
        'errors',
        'fuel_sale_id',
    ];

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'liters' => 'decimal:3',
            'price_per_liter' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'errors' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SalesImportBatch::class, 'sales_import_batch_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(FuelSale::class, 'fuel_sale_id');
    }
}

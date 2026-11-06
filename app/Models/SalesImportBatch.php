<?php

namespace App\Models;

use App\Models\Concerns\ScopesToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesImportBatch extends Model
{
    use HasFactory, ScopesToBranch;

    protected $fillable = [
        'branch_id',
        'imported_by',
        'original_filename',
        'file_path',
        'content_sha256',
        'status',
        'row_count',
        'valid_row_count',
        'invalid_row_count',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'valid_row_count' => 'integer',
            'invalid_row_count' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(SalesImportRow::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(FuelSale::class);
    }
}

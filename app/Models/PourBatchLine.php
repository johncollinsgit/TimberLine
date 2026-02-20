<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PourBatchLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'pour_batch_id',
        'order_id',
        'order_line_id',
        'scent_id',
        'size_id',
        'sku',
        'quantity',
        'wax_grams',
        'oil_grams',
        'alcohol_grams',
        'water_grams',
        'total_grams',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'wax_grams' => 'decimal:2',
        'oil_grams' => 'decimal:2',
        'alcohol_grams' => 'decimal:2',
        'water_grams' => 'decimal:2',
        'total_grams' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(PourBatch::class, 'pour_batch_id');
    }
}

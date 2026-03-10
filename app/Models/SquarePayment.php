<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SquarePayment extends Model
{
    protected $fillable = [
        'square_payment_id',
        'square_order_id',
        'square_customer_id',
        'location_id',
        'amount_money',
        'currency',
        'status',
        'source_type',
        'created_at_source',
        'raw_payload',
        'synced_at',
    ];

    protected $casts = [
        'created_at_source' => 'datetime',
        'raw_payload' => 'array',
        'synced_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SquareOrder::class, 'square_order_id', 'square_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(SquareCustomer::class, 'square_customer_id', 'square_customer_id');
    }
}

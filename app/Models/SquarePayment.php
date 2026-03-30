<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SquarePayment extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
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
        'tenant_id' => 'integer',
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

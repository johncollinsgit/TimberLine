<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SquareOrder extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'square_order_id',
        'square_customer_id',
        'location_id',
        'state',
        'total_money_amount',
        'total_money_currency',
        'closed_at',
        'source_name',
        'raw_tax_names',
        'raw_payload',
        'synced_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'raw_tax_names' => 'array',
        'raw_payload' => 'array',
        'closed_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(SquareCustomer::class, 'square_customer_id', 'square_customer_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SquarePayment::class, 'square_order_id', 'square_order_id');
    }

    public function attributions(): HasMany
    {
        return $this->hasMany(MarketingOrderEventAttribution::class, 'source_id', 'square_order_id')
            ->where('source_type', 'square_order');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMessagingUsagePeriod extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'channel', 'period_start', 'period_end', 'included_units',
        'used_units', 'reserved_units', 'provider_cost_micros', 'buyer_charge_micros',
        'tenant_direct_invoice_id', 'invoiced_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'included_units' => 'integer',
        'used_units' => 'integer',
        'reserved_units' => 'integer',
        'provider_cost_micros' => 'integer',
        'buyer_charge_micros' => 'integer',
        'tenant_direct_invoice_id' => 'integer',
        'invoiced_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAiUsageEvent extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer', 'user_id' => 'integer', 'tenant_direct_invoice_id' => 'integer',
        'duration_seconds' => 'integer', 'provider_cost_micros' => 'integer', 'buyer_charge_micros' => 'integer',
        'occurred_at' => 'datetime', 'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

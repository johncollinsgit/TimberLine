<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TenantMessagingLedgerEntry extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'tenant_messaging_credit_account_id', 'tenant_messaging_usage_period_id',
        'entry_type', 'status', 'channel', 'unit_type', 'units', 'amount_micros',
        'provider_cost_micros', 'pricing_version', 'idempotency_key', 'source_type',
        'source_id', 'metadata', 'occurred_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'tenant_messaging_credit_account_id' => 'integer',
        'tenant_messaging_usage_period_id' => 'integer',
        'units' => 'integer',
        'amount_micros' => 'integer',
        'provider_cost_micros' => 'integer',
        'source_id' => 'integer',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Messaging ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Messaging ledger entries are immutable.'));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(TenantMessagingCreditAccount::class, 'tenant_messaging_credit_account_id');
    }

    public function usagePeriod(): BelongsTo
    {
        return $this->belongsTo(TenantMessagingUsagePeriod::class, 'tenant_messaging_usage_period_id');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantMessagingCreditAccount extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'currency', 'balance_micros', 'reserved_micros',
        'low_balance_threshold_micros', 'last_funded_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'balance_micros' => 'integer',
        'reserved_micros' => 'integer',
        'low_balance_threshold_micros' => 'integer',
        'last_funded_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(TenantMessagingLedgerEntry::class);
    }

    public function availableMicros(): int
    {
        return max(0, (int) $this->balance_micros - (int) $this->reserved_micros);
    }
}

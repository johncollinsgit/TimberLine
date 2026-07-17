<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantMessagingAccount extends Model
{
    use BelongsToTenant;

    public const STATUS_READY = 'ready';

    protected $fillable = [
        'tenant_id', 'channel', 'provider', 'mode', 'status', 'provider_account_id',
        'provider_resource_id', 'sender_identifier', 'authenticated_domain', 'credentials',
        'provider_config', 'dns_records', 'registration', 'compliance_profile', 'diagnostics', 'verified_at',
        'suspended_at', 'last_error_at', 'last_error_code', 'last_error_message',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'credentials' => 'encrypted:array',
        'provider_config' => 'encrypted:array',
        'dns_records' => 'array',
        'registration' => 'array',
        'compliance_profile' => 'encrypted:array',
        'diagnostics' => 'array',
        'verified_at' => 'datetime',
        'suspended_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    protected $hidden = ['credentials', 'compliance_profile'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function senderProfiles(): HasMany
    {
        return $this->hasMany(TenantMessagingSenderProfile::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY && $this->suspended_at === null;
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMessagingSenderProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'tenant_messaging_account_id', 'channel', 'store_key', 'label',
        'display_name', 'from_email', 'reply_to_email', 'authenticated_domain',
        'reply_mode', 'verification_status', 'verified_at', 'is_default', 'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'tenant_messaging_account_id' => 'integer',
        'verified_at' => 'datetime',
        'is_default' => 'boolean',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TenantMessagingAccount::class, 'tenant_messaging_account_id');
    }
}

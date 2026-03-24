<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantEmailSetting extends Model
{
    protected $fillable = [
        'tenant_id',
        'email_provider',
        'email_enabled',
        'from_name',
        'from_email',
        'reply_to_email',
        'provider_status',
        'provider_config',
        'analytics_enabled',
        'last_tested_at',
        'last_error',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'email_enabled' => 'boolean',
        'analytics_enabled' => 'boolean',
        'provider_config' => 'encrypted:array',
        'last_tested_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

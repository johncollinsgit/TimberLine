<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessagingContactChannelState extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'marketing_profile_id',
        'phone',
        'email',
        'sms_status',
        'email_status',
        'sms_status_reason',
        'email_status_reason',
        'sms_status_changed_at',
        'email_status_changed_at',
        'provider_source',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'sms_status_changed_at' => 'datetime',
        'email_status_changed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingMessageEngagementEvent extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'store_key',
        'marketing_email_delivery_id',
        'marketing_message_delivery_id',
        'marketing_profile_id',
        'channel',
        'event_type',
        'event_hash',
        'provider',
        'provider_event_id',
        'provider_message_id',
        'link_label',
        'url',
        'normalized_url',
        'url_domain',
        'ip_address',
        'user_agent',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'marketing_email_delivery_id' => 'integer',
        'marketing_message_delivery_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function emailDelivery(): BelongsTo
    {
        return $this->belongsTo(MarketingEmailDelivery::class, 'marketing_email_delivery_id');
    }

    public function messageDelivery(): BelongsTo
    {
        return $this->belongsTo(MarketingMessageDelivery::class, 'marketing_message_delivery_id');
    }
}

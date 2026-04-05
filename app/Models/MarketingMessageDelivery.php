<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingMessageDelivery extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'campaign_id',
        'campaign_recipient_id',
        'marketing_profile_id',
        'tenant_id',
        'store_key',
        'batch_id',
        'source_label',
        'message_subject',
        'channel',
        'provider',
        'provider_message_id',
        'to_phone',
        'from_identifier',
        'variant_id',
        'attempt_number',
        'rendered_message',
        'send_status',
        'error_code',
        'error_message',
        'provider_payload',
        'sent_at',
        'delivered_at',
        'failed_at',
        'created_by',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'attempt_number' => 'integer',
        'provider_payload' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignRecipient::class, 'campaign_recipient_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignVariant::class, 'variant_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MarketingDeliveryEvent::class, 'marketing_message_delivery_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function engagementEvents(): HasMany
    {
        return $this->hasMany(MarketingMessageEngagementEvent::class, 'marketing_message_delivery_id');
    }

    public function messageJobs(): HasMany
    {
        return $this->hasMany(MarketingMessageJob::class, 'delivery_id');
    }
}

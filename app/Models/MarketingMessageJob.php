<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingMessageJob extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'campaign_id',
        'campaign_recipient_id',
        'marketing_profile_id',
        'tenant_id',
        'store_key',
        'channel',
        'job_type',
        'status',
        'attempt_count',
        'max_attempts',
        'priority',
        'available_at',
        'dispatched_at',
        'started_at',
        'completed_at',
        'failed_at',
        'delivery_id',
        'provider_message_id',
        'last_error_code',
        'last_error_message',
        'payload',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'campaign_id' => 'integer',
        'campaign_recipient_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'delivery_id' => 'integer',
        'attempt_count' => 'integer',
        'max_attempts' => 'integer',
        'priority' => 'integer',
        'available_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'payload' => 'array',
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

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(MarketingMessageDelivery::class, 'delivery_id');
    }
}

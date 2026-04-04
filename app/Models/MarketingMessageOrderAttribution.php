<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingMessageOrderAttribution extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'store_key',
        'order_id',
        'marketing_profile_id',
        'marketing_email_delivery_id',
        'marketing_message_engagement_event_id',
        'channel',
        'attribution_model',
        'attribution_window_days',
        'attributed_url',
        'normalized_url',
        'click_occurred_at',
        'order_occurred_at',
        'revenue_cents',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'order_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'marketing_email_delivery_id' => 'integer',
        'marketing_message_engagement_event_id' => 'integer',
        'attribution_window_days' => 'integer',
        'revenue_cents' => 'integer',
        'click_occurred_at' => 'datetime',
        'order_occurred_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function emailDelivery(): BelongsTo
    {
        return $this->belongsTo(MarketingEmailDelivery::class, 'marketing_email_delivery_id');
    }

    public function engagementEvent(): BelongsTo
    {
        return $this->belongsTo(MarketingMessageEngagementEvent::class, 'marketing_message_engagement_event_id');
    }
}

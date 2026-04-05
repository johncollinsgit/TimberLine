<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaign extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'store_key',
        'name',
        'slug',
        'description',
        'status',
        'channel',
        'source_label',
        'message_subject',
        'message_body',
        'message_html',
        'target_snapshot',
        'status_counts',
        'queued_at',
        'scheduled_for',
        'test_sent_at',
        'template_instance_id',
        'segment_id',
        'objective',
        'attribution_window_days',
        'coupon_code',
        'send_window_json',
        'quiet_hours_override_json',
        'created_by',
        'updated_by',
        'launched_at',
        'completed_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'template_instance_id' => 'integer',
        'send_window_json' => 'array',
        'quiet_hours_override_json' => 'array',
        'target_snapshot' => 'array',
        'status_counts' => 'array',
        'queued_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'test_sent_at' => 'datetime',
        'launched_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(MarketingSegment::class, 'segment_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MarketingCampaignVariant::class, 'campaign_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MarketingCampaignRecipient::class, 'campaign_id');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(MarketingRecommendation::class, 'campaign_id');
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(MarketingCampaignConversion::class, 'campaign_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(MarketingMessageDelivery::class, 'campaign_id');
    }

    public function messageJobs(): HasMany
    {
        return $this->hasMany(MarketingMessageJob::class, 'campaign_id');
    }

    public function templateInstance(): BelongsTo
    {
        return $this->belongsTo(MarketingTemplateInstance::class, 'template_instance_id');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(MarketingGroup::class, 'marketing_campaign_groups', 'campaign_id', 'marketing_group_id')
            ->withTimestamps();
    }

    public function performanceSnapshots(): HasMany
    {
        return $this->hasMany(MarketingVariantPerformanceSnapshot::class, 'campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

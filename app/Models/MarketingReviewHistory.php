<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketingReviewHistory extends Model
{
    protected $fillable = [
        'marketing_profile_id',
        'tenant_id',
        'marketing_review_summary_id',
        'provider',
        'integration',
        'store_key',
        'external_customer_id',
        'external_review_id',
        'rating',
        'title',
        'body',
        'reviewer_name',
        'reviewer_email',
        'is_published',
        'status',
        'submission_source',
        'is_pinned',
        'is_verified_buyer',
        'votes',
        'has_media',
        'media_count',
        'media_assets',
        'product_id',
        'order_id',
        'order_line_id',
        'variant_id',
        'product_handle',
        'product_url',
        'product_title',
        'reviewed_at',
        'submitted_at',
        'approved_at',
        'published_at',
        'rejected_at',
        'moderated_by',
        'moderation_notes',
        'notification_sent_at',
        'reward_eligibility_status',
        'reward_award_status',
        'reward_amount_cents',
        'reward_rule_key',
        'candle_cash_task_event_id',
        'candle_cash_task_completion_id',
        'source_synced_at',
        'raw_payload',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'rating' => 'integer',
        'is_published' => 'boolean',
        'is_pinned' => 'boolean',
        'is_verified_buyer' => 'boolean',
        'votes' => 'integer',
        'has_media' => 'boolean',
        'media_count' => 'integer',
        'media_assets' => 'array',
        'reviewed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
        'rejected_at' => 'datetime',
        'notification_sent_at' => 'datetime',
        'reward_amount_cents' => 'integer',
        'source_synced_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function summary(): BelongsTo
    {
        return $this->belongsTo(MarketingReviewSummary::class, 'marketing_review_summary_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function candleCashTaskEvent(): BelongsTo
    {
        return $this->belongsTo(CandleCashTaskEvent::class, 'candle_cash_task_event_id');
    }

    public function candleCashTaskCompletion(): BelongsTo
    {
        return $this->belongsTo(CandleCashTaskCompletion::class, 'candle_cash_task_completion_id');
    }

    public function displayReviewerName(): string
    {
        $name = trim((string) ($this->reviewer_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $profile = $this->relationLoaded('profile') ? $this->profile : $this->profile()->first();
        if ($profile) {
            $profileName = trim((string) ($profile->display_name ?: trim(($profile->first_name ?? '') . ' ' . ($profile->last_name ?? ''))));
            if ($profileName !== '') {
                return $profileName;
            }

            if (filled($profile->email)) {
                return Str::before((string) $profile->email, '@');
            }
        }

        if (filled($this->reviewer_email)) {
            return Str::before((string) $this->reviewer_email, '@');
        }

        return 'Forestry customer';
    }
}

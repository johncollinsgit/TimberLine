<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MarketingProfile extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'first_name',
        'last_name',
        'email',
        'normalized_email',
        'phone',
        'normalized_phone',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'accepts_email_marketing',
        'accepts_sms_marketing',
        'email_opted_out_at',
        'sms_opted_out_at',
        'source_channels',
        'marketing_score',
        'last_marketing_score_at',
        'notes',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'accepts_email_marketing' => 'boolean',
        'accepts_sms_marketing' => 'boolean',
        'email_opted_out_at' => 'datetime',
        'sms_opted_out_at' => 'datetime',
        'source_channels' => 'array',
        'marketing_score' => 'decimal:2',
        'last_marketing_score_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(MarketingProfileLink::class);
    }

    public function identityReviews(): HasMany
    {
        return $this->hasMany(MarketingIdentityReview::class, 'proposed_marketing_profile_id');
    }

    public function externalCampaignStats(): HasMany
    {
        return $this->hasMany(MarketingExternalCampaignStat::class);
    }

    public function campaignRecipients(): HasMany
    {
        return $this->hasMany(MarketingCampaignRecipient::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(MarketingRecommendation::class, 'marketing_profile_id');
    }

    public function scoreHistory(): HasMany
    {
        return $this->hasMany(MarketingProfileScore::class, 'marketing_profile_id');
    }

    public function campaignConversions(): HasMany
    {
        return $this->hasMany(MarketingCampaignConversion::class, 'marketing_profile_id');
    }

    public function messageDeliveries(): HasMany
    {
        return $this->hasMany(MarketingMessageDelivery::class, 'marketing_profile_id');
    }

    public function consentEvents(): HasMany
    {
        return $this->hasMany(MarketingConsentEvent::class, 'marketing_profile_id');
    }

    public function consentRequests(): HasMany
    {
        return $this->hasMany(MarketingConsentRequest::class, 'marketing_profile_id');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(MarketingGroup::class, 'marketing_group_members', 'marketing_profile_id', 'marketing_group_id')
            ->withPivot(['added_by', 'created_at'])
            ->withTimestamps();
    }

    public function emailDeliveries(): HasMany
    {
        return $this->hasMany(MarketingEmailDelivery::class, 'marketing_profile_id');
    }

    public function candleCashBalance(): HasOne
    {
        return $this->hasOne(CandleCashBalance::class, 'marketing_profile_id');
    }

    public function candleCashTransactions(): HasMany
    {
        return $this->hasMany(CandleCashTransaction::class, 'marketing_profile_id');
    }

    public function candleCashRedemptions(): HasMany
    {
        return $this->hasMany(CandleCashRedemption::class, 'marketing_profile_id');
    }

    public function candleCashTaskCompletions(): HasMany
    {
        return $this->hasMany(CandleCashTaskCompletion::class, 'marketing_profile_id');
    }

    public function candleCashTaskEvents(): HasMany
    {
        return $this->hasMany(CandleCashTaskEvent::class, 'marketing_profile_id');
    }

    public function candleCashReferralsMade(): HasMany
    {
        return $this->hasMany(CandleCashReferral::class, 'referrer_marketing_profile_id');
    }

    public function candleCashReferralsReceived(): HasMany
    {
        return $this->hasMany(CandleCashReferral::class, 'referred_marketing_profile_id');
    }

    public function storefrontEvents(): HasMany
    {
        return $this->hasMany(MarketingStorefrontEvent::class, 'marketing_profile_id');
    }

    public function externalProfiles(): HasMany
    {
        return $this->hasMany(CustomerExternalProfile::class, 'marketing_profile_id');
    }

    public function birthdayProfile(): HasOne
    {
        return $this->hasOne(CustomerBirthdayProfile::class, 'marketing_profile_id');
    }

    public function birthdayRewardIssuances(): HasMany
    {
        return $this->hasMany(BirthdayRewardIssuance::class, 'marketing_profile_id');
    }

    public function birthdayMessageEvents(): HasMany
    {
        return $this->hasMany(BirthdayMessageEvent::class, 'marketing_profile_id');
    }

    public function reviewSummaries(): HasMany
    {
        return $this->hasMany(MarketingReviewSummary::class, 'marketing_profile_id');
    }

    public function reviewHistory(): HasMany
    {
        return $this->hasMany(MarketingReviewHistory::class, 'marketing_profile_id');
    }

    public function wishlistItems(): HasMany
    {
        return $this->hasMany(MarketingProfileWishlistItem::class, 'marketing_profile_id');
    }

    public function wishlistLists(): HasMany
    {
        return $this->hasMany(MarketingWishlistList::class, 'marketing_profile_id');
    }

    public function automationEvents(): HasMany
    {
        return $this->hasMany(MarketingAutomationEvent::class, 'marketing_profile_id');
    }
}

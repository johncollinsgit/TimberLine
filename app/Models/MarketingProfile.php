<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingProfile extends Model
{
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'normalized_email',
        'phone',
        'normalized_phone',
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
        'accepts_email_marketing' => 'boolean',
        'accepts_sms_marketing' => 'boolean',
        'email_opted_out_at' => 'datetime',
        'sms_opted_out_at' => 'datetime',
        'source_channels' => 'array',
        'marketing_score' => 'decimal:2',
        'last_marketing_score_at' => 'datetime',
    ];

    public function links(): HasMany
    {
        return $this->hasMany(MarketingProfileLink::class);
    }

    public function identityReviews(): HasMany
    {
        return $this->hasMany(MarketingIdentityReview::class, 'proposed_marketing_profile_id');
    }
}

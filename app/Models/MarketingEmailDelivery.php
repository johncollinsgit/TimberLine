<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingEmailDelivery extends Model
{
    protected $fillable = [
        'marketing_campaign_recipient_id',
        'marketing_profile_id',
        'sendgrid_message_id',
        'email',
        'status',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'failed_at',
        'raw_payload',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignRecipient::class, 'marketing_campaign_recipient_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }
}

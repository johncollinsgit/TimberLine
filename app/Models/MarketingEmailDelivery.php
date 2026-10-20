<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingEmailDelivery extends Model
{
    use HasTenantScope;

    protected $hidden = ['legacy_marketing_profile_id'];

    protected $fillable = [
        'marketing_campaign_recipient_id',
        'marketing_profile_id',
        'tenant_id',
        'store_key',
        'batch_id',
        'source_label',
        'message_subject',
        'provider',
        'provider_message_id',
        'campaign_type',
        'template_key',
        'sendgrid_message_id',
        'email',
        'status',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'failed_at',
        'raw_payload',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'raw_payload' => 'array',
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function save(array $options = [])
    {
        if ($this->exists && ($this->provider === 'ses_tenant' || data_get($this->metadata, 'ses_attempt')
            || static::query()->whereKey($this->id)->where('provider', 'ses_tenant')->exists())) {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($options) {
                static::query()->whereKey($this->id)->lockForUpdate()->first();

                return parent::save($options);
            });
        }

        return parent::save($options);
    }

    protected static function booted(): void
    {
        static::saving(function (self $delivery): void {
            if (! $delivery->exists) {
                return;
            }
            $stored = static::query()->find($delivery->id);
            $attempt = data_get($stored?->metadata, 'ses_attempt');
            if (! $attempt) {
                return;
            }
            // Legacy callers save models loaded before provider HTTP. The SES
            // claim and receipt are immutable to those stale model instances.
            $delivery->metadata = [...(array) $delivery->metadata, 'ses_attempt' => $attempt];
            $raw = (array) $delivery->raw_payload;
            $storedRaw = (array) $stored->raw_payload;
            if (array_diff((array) ($storedRaw['received_event_hashes'] ?? []), (array) ($raw['received_event_hashes'] ?? []))) {
                foreach (['received_event_hashes', 'events', 'last_event', 'last_event_at'] as $key) {
                    if (array_key_exists($key, $storedRaw)) {
                        $raw[$key] = $storedRaw[$key];
                    }
                }
                $delivery->raw_payload = $raw;
                if (isset($stored->metadata['failure_policy'])) {
                    $delivery->metadata = [...$delivery->metadata, 'failure_policy' => $stored->metadata['failure_policy']];
                }
            }
            $delivery->provider = 'ses_tenant';
            $delivery->provider_message_id = $stored->provider_message_id ?: $delivery->provider_message_id;
            if (in_array($stored->status, ['delivered', 'opened', 'clicked'], true)) {
                $rank = ['delivered' => 1, 'opened' => 2, 'clicked' => 3];
                if (($rank[$delivery->status] ?? 0) < $rank[$stored->status]) {
                    $delivery->status = $stored->status;
                }
            } elseif (in_array($attempt['state'] ?? '', ['submitting', 'unknown'], true) && $delivery->status === 'failed') {
                $delivery->status = 'unknown';
                $delivery->failed_at = null;
            }
        });
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignRecipient::class, 'marketing_campaign_recipient_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function engagementEvents(): HasMany
    {
        return $this->hasMany(MarketingMessageEngagementEvent::class, 'marketing_email_delivery_id');
    }

    public function messageOrderAttributions(): HasMany
    {
        return $this->hasMany(MarketingMessageOrderAttribution::class, 'marketing_email_delivery_id');
    }
}

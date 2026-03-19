<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use App\Models\Concerns\TracksLegacyCandleCashCompatibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingConsentRequest extends Model
{
    use HasTenantScope;
    use TracksLegacyCandleCashCompatibility;

    protected $fillable = [
        'tenant_id',
        'marketing_profile_id',
        'channel',
        'token',
        'status',
        'source_type',
        'source_id',
        'payload',
        'requested_at',
        'confirmed_at',
        'revoked_at',
        'expires_at',
        'reward_awarded_candle_cash',
        'reward_awarded_points',
        'reward_awarded_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'payload' => 'array',
        'requested_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'expires_at' => 'datetime',
        'reward_awarded_candle_cash' => 'integer',
        'reward_awarded_points' => 'integer',
        'reward_awarded_at' => 'datetime',
    ];

    public function getRewardAwardedCandleCashAttribute($value): int
    {
        if ($value !== null) {
            return (int) $value;
        }

        if (array_key_exists('reward_awarded_points', $this->attributes) && $this->attributes['reward_awarded_points'] !== null) {
            $this->recordLegacyCandleCashCompatibility('marketing_consent_requests.reward_awarded_points', 'fallback_read', __METHOD__);
        }

        return (int) ($this->attributes['reward_awarded_points'] ?? 0);
    }

    public function setRewardAwardedCandleCashAttribute($value): void
    {
        $normalized = max(0, (int) $value);

        $this->attributes['reward_awarded_candle_cash'] = $normalized;
        $this->attributes['reward_awarded_points'] = $normalized;
    }

    public function getRewardAwardedPointsAttribute($value): int
    {
        if ($value !== null) {
            $this->recordLegacyCandleCashCompatibility('marketing_consent_requests.reward_awarded_points', 'legacy_read', __METHOD__);

            return (int) $value;
        }

        if (array_key_exists('reward_awarded_candle_cash', $this->attributes) && $this->attributes['reward_awarded_candle_cash'] !== null) {
            $this->recordLegacyCandleCashCompatibility('marketing_consent_requests.reward_awarded_points', 'legacy_read', __METHOD__);
        }

        return (int) ($this->attributes['reward_awarded_candle_cash'] ?? 0);
    }

    public function setRewardAwardedPointsAttribute($value): void
    {
        $this->recordLegacyCandleCashCompatibility('marketing_consent_requests.reward_awarded_points', 'legacy_write', __METHOD__);

        $normalized = max(0, (int) $value);

        $this->attributes['reward_awarded_points'] = $normalized;
        $this->attributes['reward_awarded_candle_cash'] = $normalized;
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

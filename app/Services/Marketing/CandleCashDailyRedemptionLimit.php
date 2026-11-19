<?php

namespace App\Services\Marketing;

use App\Models\CandleCashRedemption;
use App\Models\MarketingProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** The canonical customer lock must be held when reserving a new redemption. */
class CandleCashDailyRedemptionLimit
{
    public const MESSAGE = 'Candle Cash can only be redeemed once per customer per day (Eastern Time). Please try again after midnight Eastern Time.';

    public function rules(?int $tenantId): array
    {
        $rules = app(CandleCashChannelPolicy::class)->rules($tenantId);

        return [
            'maximum' => max(0, (int) ($rules['max_redemptions_per_customer_per_day'] ?? 0)),
            'timezone' => (string) ($rules['redemption_timezone'] ?? 'UTC'),
        ];
    }

    public function status(MarketingProfile $profile, bool $usesOnly = false, ?int $exceptRedemptionId = null): array
    {
        $rules = $this->rules($profile->tenant_id);
        if ($rules['maximum'] === 0) {
            return ['reached' => false, 'reset_at' => null, ...$rules];
        }

        // Compute both midnights in local time before converting, including DST days.
        $start = CarbonImmutable::now($rules['timezone'])->startOfDay();
        $end = $start->addDay();
        $startUtc = $start->utc();
        $endUtc = $end->utc();
        $count = CandleCashRedemption::query()
            ->where('marketing_profile_id', $profile->id)
            ->whereHas('profile', fn (Builder $query) => $query->where('tenant_id', $profile->tenant_id))
            ->when($exceptRedemptionId !== null, fn (Builder $query) => $query->whereKeyNot($exceptRedemptionId))
            ->where(function (Builder $query) use ($usesOnly, $startUtc, $endUtc): void {
                $query->where(function (Builder $used) use ($startUtc, $endUtc): void {
                    $used->where('status', 'redeemed')->where('redeemed_at', '>=', $startUtc)->where('redeemed_at', '<', $endUtc);
                });
                if (! $usesOnly) {
                    // An issued code reserves today's allowance even if order ingestion is delayed.
                    // Canceling a reservation does not allow another code on the same day.
                    $query->orWhere(function (Builder $issued) use ($startUtc, $endUtc): void {
                        $issued->where('issued_at', '>=', $startUtc)->where('issued_at', '<', $endUtc);
                    })->orWhere(function (Builder $legacy) use ($startUtc, $endUtc): void {
                        $legacy->whereNull('issued_at')->where('created_at', '>=', $startUtc)->where('created_at', '<', $endUtc);
                    });
                }
            })->count();

        return ['reached' => $count >= $rules['maximum'], 'reset_at' => $endUtc->toIso8601String(), ...$rules];
    }

    public function hasReusableOnlineCode(MarketingProfile $profile): bool
    {
        if ($this->status($profile, usesOnly: true)['reached']) {
            return false;
        }

        return CandleCashRedemption::query()->where('marketing_profile_id', $profile->id)
            ->where('status', 'issued')
            ->where(fn (Builder $query) => $query->whereNull('platform')->orWhereIn('platform', ['shopify', 'public_lookup', 'modern_forestry_ios']))
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }
}

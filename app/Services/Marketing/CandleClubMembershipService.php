<?php

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CandleClubMembershipService
{
    public function statusForProfile(?MarketingProfile $profile): ?string
    {
        if (! $profile) {
            return null;
        }

        if ($this->profileChannelSignalsMembership($profile)) {
            return 'active_candle_club_member';
        }

        if ($this->externalProfileSignalsMembership($profile)) {
            return 'active_candle_club_member';
        }

        if ($this->groupSignalsMembership($profile)) {
            return 'active_candle_club_member';
        }

        if ($this->orderHistorySignalsMembership($profile)) {
            return 'active_candle_club_member';
        }

        return null;
    }

    public function isActiveMember(?MarketingProfile $profile): bool
    {
        return $this->statusForProfile($profile) === 'active_candle_club_member';
    }

    protected function profileChannelSignalsMembership(MarketingProfile $profile): bool
    {
        return $this->normalizedChannels($profile->source_channels)->contains('candle_club');
    }

    protected function externalProfileSignalsMembership(MarketingProfile $profile): bool
    {
        $profiles = $profile->relationLoaded('externalProfiles')
            ? $profile->externalProfiles
            : $profile->externalProfiles()->get(['id', 'vip_tier', 'source_channels']);

        return $profiles->contains(function (CustomerExternalProfile $external): bool {
            $channels = $this->normalizedChannels($external->source_channels);
            $vipTier = Str::lower(trim((string) ($external->vip_tier ?? '')));

            return $channels->contains('candle_club')
                || ($vipTier !== '' && str_contains($vipTier, 'candle club'));
        });
    }

    protected function groupSignalsMembership(MarketingProfile $profile): bool
    {
        return $profile->groups()
            ->whereRaw('lower(name) like ?', ['%candle club%'])
            ->exists();
    }

    protected function orderHistorySignalsMembership(MarketingProfile $profile): bool
    {
        $orderIds = $profile->links()
            ->where('source_type', 'order')
            ->pluck('source_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->values();

        if ($this->orderLinesContainMembershipProduct($orderIds)) {
            return true;
        }

        $shopifyCustomerIds = $profile->links()
            ->where('source_type', 'shopify_customer')
            ->pluck('source_id')
            ->map(function ($value): ?string {
                $sourceId = trim((string) $value);
                if ($sourceId === '') {
                    return null;
                }

                if (str_contains($sourceId, ':')) {
                    [, $sourceId] = explode(':', $sourceId, 2);
                }

                $normalized = trim($sourceId);

                return $normalized !== '' ? $normalized : null;
            })
            ->filter()
            ->values();

        if ($shopifyCustomerIds->isEmpty()) {
            return false;
        }

        $linkedOrderIds = Order::query()
            ->whereIn('shopify_customer_id', $shopifyCustomerIds->all())
            ->pluck('id');

        return $this->orderLinesContainMembershipProduct($linkedOrderIds);
    }

    protected function orderLinesContainMembershipProduct(Collection $orderIds): bool
    {
        if ($orderIds->isEmpty()) {
            return false;
        }

        return OrderLine::query()
            ->whereIn('order_id', $orderIds->all())
            ->where(function ($query): void {
                $query->whereRaw("lower(coalesce(raw_title, '')) like ?", ['%candle club%'])
                    ->orWhereRaw("lower(coalesce(raw_variant, '')) like ?", ['%candle club%']);
            })
            ->exists();
    }

    /**
     * @param  array<int,mixed>|null  $channels
     * @return Collection<int,string>
     */
    protected function normalizedChannels(?array $channels): Collection
    {
        return collect((array) $channels)
            ->map(fn ($value): string => Str::lower(trim((string) $value)))
            ->filter();
    }
}

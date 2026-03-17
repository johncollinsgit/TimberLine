<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignConversion;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfileLink;
use App\Models\MarketingSetting;
use App\Models\Order;
use App\Models\SquareOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MarketingConversionAttributionService
{
    public function __construct(
        protected MarketingCampaignConversionAttributionSnapshotBuilder $snapshotBuilder
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,int>
     */
    public function attributeForOrder(Order $order, array $options = []): array
    {
        $profileIds = $this->profileIdsForOrder($order);
        $convertedAt = $order->ordered_at ?: $order->created_at ?: now();
        $orderTotal = $this->orderTotalFromOrder($order);
        $couponSignals = array_values(array_unique(array_merge(
            $this->couponSignalsFromOrder($order),
            $this->normalizeCouponSignals((array) ($options['coupon_signals'] ?? []))
        )));

        return $this->attributeForProfiles(
            profileIds: $profileIds,
            sourceType: 'order',
            sourceId: (string) $order->id,
            convertedAt: $convertedAt,
            orderTotal: $orderTotal,
            couponSignals: $couponSignals,
            options: $options
        );
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,int>
     */
    public function attributeForSquareOrder(SquareOrder $order, array $options = []): array
    {
        $profileIds = $this->profileIdsForSquareOrder($order);
        $convertedAt = $order->closed_at ?: $order->created_at ?: now();
        $orderTotal = $order->total_money_amount !== null
            ? round(((int) $order->total_money_amount) / 100, 2)
            : null;
        $couponSignals = array_values(array_unique(array_merge(
            $this->couponSignalsFromSquareOrder($order),
            $this->normalizeCouponSignals((array) ($options['coupon_signals'] ?? []))
        )));

        return $this->attributeForProfiles(
            profileIds: $profileIds,
            sourceType: 'square_order',
            sourceId: (string) $order->square_order_id,
            convertedAt: $convertedAt,
            orderTotal: $orderTotal,
            couponSignals: $couponSignals,
            options: $options
        );
    }

    /**
     * @param array<int,int> $profileIds
     * @param array<int,string> $couponSignals
     * @param array<string,mixed> $options
     * @return array<string,int>
     */
    protected function attributeForProfiles(
        array $profileIds,
        string $sourceType,
        string $sourceId,
        mixed $convertedAt,
        ?float $orderTotal,
        array $couponSignals,
        array $options = []
    ): array {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $summary = [
            'sources_processed' => 1,
            'profiles_resolved' => 0,
            'conversions_created' => 0,
            'conversions_updated' => 0,
            'conversions_skipped' => 0,
        ];

        if ($sourceId === '' || $profileIds === []) {
            $summary['conversions_skipped']++;
            return $summary;
        }

        $convertedAt = $this->asDate($convertedAt) ?: now();

        foreach (array_values(array_unique($profileIds)) as $profileId) {
            $summary['profiles_resolved']++;

            $touches = $this->eligibleTouchesForProfile((int) $profileId, $convertedAt);
            if ($touches->isEmpty()) {
                $summary['conversions_skipped']++;
                continue;
            }

            $primaryCampaignIds = [];

            foreach ($touches as $touch) {
                $couponCode = trim((string) ($touch->campaign?->coupon_code ?? ''));
                if ($couponCode === '' || ! $this->matchesCouponCode($couponCode, $couponSignals)) {
                    continue;
                }

                $result = $this->persistConversion(
                    campaignId: (int) $touch->campaign_id,
                    profileId: (int) $profileId,
                    recipientId: $touch->campaign_recipient_id ? (int) $touch->campaign_recipient_id : null,
                    attributionType: 'code_based',
                    sourceType: $sourceType,
                    sourceId: $sourceId,
                    convertedAt: $convertedAt,
                    orderTotal: $orderTotal,
                    notes: 'Matched campaign coupon code on source order payload.',
                    dryRun: $dryRun
                );

                $summary['conversions_created'] += $result['created'];
                $summary['conversions_updated'] += $result['updated'];
                $primaryCampaignIds[] = (int) $touch->campaign_id;
            }

            $lastTouch = $touches->sortByDesc(fn (MarketingMessageDelivery $delivery) => $delivery->sent_at?->timestamp ?? 0)->first();
            if ($lastTouch) {
                $result = $this->persistConversion(
                    campaignId: (int) $lastTouch->campaign_id,
                    profileId: (int) $profileId,
                    recipientId: $lastTouch->campaign_recipient_id ? (int) $lastTouch->campaign_recipient_id : null,
                    attributionType: 'last_touch',
                    sourceType: $sourceType,
                    sourceId: $sourceId,
                    convertedAt: $convertedAt,
                    orderTotal: $orderTotal,
                    notes: 'Most recent delivered/sent touch within attribution window.',
                    dryRun: $dryRun
                );

                $summary['conversions_created'] += $result['created'];
                $summary['conversions_updated'] += $result['updated'];
                $primaryCampaignIds[] = (int) $lastTouch->campaign_id;
            }

            $assistTouches = $touches
                ->filter(fn (MarketingMessageDelivery $delivery) => ! in_array((int) $delivery->campaign_id, $primaryCampaignIds, true))
                ->groupBy('campaign_id')
                ->map(fn ($group) => $group->sortByDesc(fn (MarketingMessageDelivery $delivery) => $delivery->sent_at?->timestamp ?? 0)->first())
                ->filter();

            foreach ($assistTouches as $assistTouch) {
                $result = $this->persistConversion(
                    campaignId: (int) $assistTouch->campaign_id,
                    profileId: (int) $profileId,
                    recipientId: $assistTouch->campaign_recipient_id ? (int) $assistTouch->campaign_recipient_id : null,
                    attributionType: 'assisted',
                    sourceType: $sourceType,
                    sourceId: $sourceId,
                    convertedAt: $convertedAt,
                    orderTotal: $orderTotal,
                    notes: 'Additional campaign touch within attribution window.',
                    dryRun: $dryRun
                );

                $summary['conversions_created'] += $result['created'];
                $summary['conversions_updated'] += $result['updated'];
            }

            if ($touches->isNotEmpty() && $summary['conversions_created'] === 0 && $summary['conversions_updated'] === 0) {
                $summary['conversions_skipped']++;
            }
        }

        return $summary;
    }

    /**
     * @return array{created:int,updated:int}
     */
    protected function persistConversion(
        int $campaignId,
        int $profileId,
        ?int $recipientId,
        string $attributionType,
        string $sourceType,
        string $sourceId,
        CarbonImmutable $convertedAt,
        ?float $orderTotal,
        ?string $notes,
        bool $dryRun
    ): array {
        $existing = MarketingCampaignConversion::query()
            ->where('campaign_id', $campaignId)
            ->where('marketing_profile_id', $profileId)
            ->where('attribution_type', $attributionType)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();

        if ($dryRun) {
            return ['created' => $existing ? 0 : 1, 'updated' => $existing ? 1 : 0];
        }

        $model = MarketingCampaignConversion::query()->updateOrCreate(
            [
                'campaign_id' => $campaignId,
                'marketing_profile_id' => $profileId,
                'attribution_type' => $attributionType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ],
            [
                'campaign_recipient_id' => $recipientId,
                'converted_at' => $convertedAt,
                'order_total' => $orderTotal,
                'notes' => $notes,
            ]
        );

        $snapshot = $this->snapshotBuilder->build(
            campaignId: $campaignId,
            profileId: $profileId,
            sourceType: $sourceType,
            sourceId: $sourceId,
            existingSnapshot: is_array($model->attribution_snapshot ?? null) ? $model->attribution_snapshot : []
        );

        if ($snapshot !== (is_array($model->attribution_snapshot ?? null) ? $model->attribution_snapshot : [])) {
            $model->forceFill([
                'attribution_snapshot' => $snapshot,
            ])->save();
        }

        if ($recipientId) {
            $recipient = $model->recipient;
            if ($recipient && in_array((string) $recipient->status, ['sent', 'delivered', 'undelivered'], true)) {
                $recipient->forceFill(['status' => 'converted'])->save();
            }
        }

        return ['created' => $model->wasRecentlyCreated ? 1 : 0, 'updated' => $model->wasRecentlyCreated ? 0 : 1];
    }

    /**
     * @return \Illuminate\Support\Collection<int,MarketingMessageDelivery>
     */
    protected function eligibleTouchesForProfile(int $profileId, CarbonImmutable $convertedAt): Collection
    {
        $defaultWindowDays = $this->defaultAttributionWindowDays();

        $touches = MarketingMessageDelivery::query()
            ->with(['campaign:id,attribution_window_days,coupon_code'])
            ->where('marketing_profile_id', $profileId)
            ->whereNotNull('campaign_id')
            ->whereNotNull('sent_at')
            ->where('sent_at', '<=', $convertedAt)
            ->whereIn('send_status', ['sent', 'delivered', 'undelivered'])
            ->orderByDesc('sent_at')
            ->get();

        return $touches->filter(function (MarketingMessageDelivery $delivery) use ($convertedAt, $defaultWindowDays): bool {
            $window = max(1, (int) ($delivery->campaign?->attribution_window_days ?? $defaultWindowDays));
            $sentAt = $this->asDate($delivery->sent_at);
            if (! $sentAt) {
                return false;
            }

            return $convertedAt->diffInDays($sentAt) <= $window;
        });
    }

    /**
     * @return array<int,int>
     */
    protected function profileIdsForOrder(Order $order): array
    {
        $ids = MarketingProfileLink::query()
            ->where(function ($query) use ($order): void {
                $query->where(function ($nested) use ($order): void {
                    $nested->where('source_type', 'order')->where('source_id', (string) $order->id);
                });

                if ($order->shopify_order_id) {
                    $shopifySourceId = (string) ($order->shopify_store_key ?: $order->shopify_store ?: 'unknown') . ':' . $order->shopify_order_id;
                    $query->orWhere(function ($nested) use ($shopifySourceId): void {
                        $nested->where('source_type', 'shopify_order')->where('source_id', $shopifySourceId);
                    });
                }
            })
            ->pluck('marketing_profile_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        return $ids;
    }

    /**
     * @return array<int,int>
     */
    protected function profileIdsForSquareOrder(SquareOrder $order): array
    {
        return MarketingProfileLink::query()
            ->where(function ($query) use ($order): void {
                $query->where(function ($nested) use ($order): void {
                    $nested->where('source_type', 'square_order')
                        ->where('source_id', (string) $order->square_order_id);
                });

                if ($order->square_customer_id) {
                    $query->orWhere(function ($nested) use ($order): void {
                        $nested->where('source_type', 'square_customer')
                            ->where('source_id', (string) $order->square_customer_id);
                    });
                }
            })
            ->pluck('marketing_profile_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    protected function orderTotalFromOrder(Order $order): ?float
    {
        foreach (['total_price', 'total', 'grand_total', 'order_total', 'subtotal_price'] as $key) {
            $value = $order->getAttribute($key);
            if ($value === null || $value === '') {
                continue;
            }

            if (is_numeric($value)) {
                return round((float) $value, 2);
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    protected function couponSignalsFromOrder(Order $order): array
    {
        $signals = [];

        foreach (['coupon_code', 'discount_code', 'promo_code'] as $key) {
            $value = trim((string) $order->getAttribute($key));
            if ($value !== '') {
                $signals[] = $value;
            }
        }

        $discountCodes = $order->getAttribute('discount_codes');
        if (is_array($discountCodes)) {
            foreach ($discountCodes as $code) {
                $string = trim((string) $code);
                if ($string !== '') {
                    $signals[] = $string;
                }
            }
        } elseif (is_string($discountCodes) && trim($discountCodes) !== '') {
            foreach (preg_split('/[,|;]/', $discountCodes) ?: [] as $code) {
                $string = trim((string) $code);
                if ($string !== '') {
                    $signals[] = $string;
                }
            }
        }

        return $this->normalizeCouponSignals($signals);
    }

    /**
     * @return array<int,string>
     */
    protected function couponSignalsFromSquareOrder(SquareOrder $order): array
    {
        $signals = [];
        $payload = is_array($order->raw_payload) ? $order->raw_payload : [];

        $metadataCoupon = trim((string) data_get($payload, 'metadata.coupon_code', ''));
        if ($metadataCoupon !== '') {
            $signals[] = $metadataCoupon;
        }

        foreach ((array) data_get($payload, 'discounts', []) as $discount) {
            $name = trim((string) data_get($discount, 'name', ''));
            if ($name !== '') {
                $signals[] = $name;
            }

            $code = trim((string) data_get($discount, 'code', ''));
            if ($code !== '') {
                $signals[] = $code;
            }
        }

        foreach ((array) data_get($payload, 'line_items', []) as $line) {
            foreach ((array) data_get($line, 'applied_discounts', []) as $discount) {
                $name = trim((string) data_get($discount, 'name', ''));
                if ($name !== '') {
                    $signals[] = $name;
                }

                $code = trim((string) data_get($discount, 'code', ''));
                if ($code !== '') {
                    $signals[] = $code;
                }
            }
        }

        return $this->normalizeCouponSignals($signals);
    }

    /**
     * @param array<int,string> $rawSignals
     * @return array<int,string>
     */
    protected function normalizeCouponSignals(array $rawSignals): array
    {
        return collect($rawSignals)
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->map(fn (string $value) => preg_replace('/[^A-Z0-9_-]+/', '', $value) ?? '')
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $couponSignals
     */
    protected function matchesCouponCode(string $couponCode, array $couponSignals): bool
    {
        $normalized = preg_replace('/[^A-Z0-9_-]+/', '', strtoupper(trim($couponCode))) ?? '';
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $couponSignals, true);
    }

    protected function defaultAttributionWindowDays(): int
    {
        $setting = MarketingSetting::query()->where('key', 'attribution_last_touch_days')->first();
        $days = (int) data_get($setting?->value, 'days', 14);

        return max(1, $days ?: 14);
    }

    protected function asDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance(\DateTime::createFromInterface($value));
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable) {
            return null;
        }
    }
}

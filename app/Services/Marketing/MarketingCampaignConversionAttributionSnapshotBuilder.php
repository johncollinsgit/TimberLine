<?php

namespace App\Services\Marketing;

use App\Models\BirthdayRewardIssuance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReferral;
use App\Models\MarketingCampaign;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\SquareOrder;
use App\Services\Shopify\Dashboard\ShopifyEmbeddedDashboardAttributionClassifier;

class MarketingCampaignConversionAttributionSnapshotBuilder
{
    /**
     * @var array<int,string>
     */
    protected array $fields = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'referrer',
        'referring_site',
        'landing_site',
        'landing_page',
        'source_url',
        'source_name',
        'source_type',
        'source_identifier',
        'shopify_store_key',
        'ingested_attribution_version',
        'capture_context',
        'capture_contexts',
        'field_confidence',
    ];

    public function __construct(
        protected MarketingAttributionSourceMetaBuilder $sourceMetaBuilder,
        protected ShopifyEmbeddedDashboardAttributionClassifier $classifier
    ) {
    }

    /**
     * @param  array<string,mixed>  $existingSnapshot
     * @return array<string,mixed>
     */
    public function build(
        int $campaignId,
        int $profileId,
        string $sourceType,
        string $sourceId,
        array $existingSnapshot = []
    ): array {
        $campaign = MarketingCampaign::query()->find($campaignId, ['id', 'channel']);
        $explicitChannel = $this->explicitChannel($campaign?->channel);

        $mergedMeta = $this->sourceMetaBuilder->mergeSourceMeta(
            $existingSnapshot,
            $this->resolveSourceMeta($profileId, $sourceType, $sourceId)
        );

        $classification = $this->classifier->classify([
            'explicitChannel' => $explicitChannel,
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
            'sourceMeta' => $mergedMeta,
        ]);

        $snapshot = [
            ...$this->snapshotFields($mergedMeta),
            'channel' => $classification['channel'],
            'confidence' => $classification['confidence'],
            'matched_by' => $classification['matchedBy'],
            'matched_value' => $classification['matchedValue'],
            'campaign_channel' => $this->nullableString($campaign?->channel),
            'explicit_channel' => $explicitChannel,
            'source_type' => $sourceType !== '' ? $sourceType : null,
            'source_id' => $sourceId !== '' ? $sourceId : null,
            'attribution_version' => 1,
        ];

        $snapshot = array_filter($snapshot, function ($value): bool {
            if (is_array($value)) {
                return $value !== [];
            }

            return $value !== null && $value !== '';
        });

        if ($this->semanticSnapshot($existingSnapshot) === $this->semanticSnapshot($snapshot)) {
            return $existingSnapshot;
        }

        $snapshot['captured_at'] = now()->toIso8601String();

        return $snapshot;
    }

    /**
     * @return array<string,mixed>
     */
    protected function resolveSourceMeta(int $profileId, string $sourceType, string $sourceId): array
    {
        $sourceType = strtolower(trim($sourceType));
        $sourceId = trim($sourceId);

        $meta = $this->linkMeta($profileId, $sourceType, $sourceId);

        if ($sourceType === 'order' && is_numeric($sourceId)) {
            $order = Order::query()->find((int) $sourceId, [
                'id',
                'source',
                'attribution_meta',
                'shopify_store_key',
                'shopify_store',
                'shopify_order_id',
                'shopify_customer_id',
            ]);

            if ($order) {
                $meta = $this->sourceMetaBuilder->mergeSourceMeta(
                    $meta,
                    $this->sourceMetaBuilder->storedOrderAttributionMeta($order)
                );

                $meta = $this->sourceMetaBuilder->mergeSourceMeta($meta, array_filter([
                    'source_name' => $this->nullableString($order->source),
                    'shopify_store_key' => $this->nullableString($order->shopify_store_key ?: $order->shopify_store),
                ]));

                $storeKey = (string) ($order->shopify_store_key ?: $order->shopify_store ?: 'unknown');

                if ($order->shopify_customer_id) {
                    $meta = $this->sourceMetaBuilder->mergeSourceMeta(
                        $meta,
                        $this->linkMeta($profileId, 'shopify_customer', $storeKey . ':' . $order->shopify_customer_id)
                    );
                }

                if ($order->shopify_order_id) {
                    $meta = $this->sourceMetaBuilder->mergeSourceMeta(
                        $meta,
                        $this->linkMeta($profileId, 'shopify_order', $storeKey . ':' . $order->shopify_order_id)
                    );
                }

                $meta = $this->sourceMetaBuilder->mergeSourceMeta($meta, $this->relatedOrderMeta($order));
            }
        }

        if ($sourceType === 'square_order') {
            $squareOrder = SquareOrder::query()
                ->where('square_order_id', $sourceId)
                ->first(['square_order_id', 'source_name']);

            if ($squareOrder) {
                $meta = $this->sourceMetaBuilder->mergeSourceMeta($meta, array_filter([
                    'source_name' => $this->nullableString($squareOrder->source_name),
                    'source_type' => 'square_order',
                ]));
            }
        }

        return $meta;
    }

    /**
     * @return array<string,mixed>
     */
    protected function relatedOrderMeta(Order $order): array
    {
        $meta = [];

        CandleCashReferral::query()
            ->where('qualifying_order_id', (string) $order->id)
            ->get(['metadata'])
            ->each(function (CandleCashReferral $referral) use (&$meta): void {
                $meta = $this->sourceMetaBuilder->mergeSourceMeta(
                    $meta,
                    is_array($referral->metadata ?? null) ? $referral->metadata : []
                );
            });

        BirthdayRewardIssuance::query()
            ->where('order_id', $order->id)
            ->get(['metadata'])
            ->each(function (BirthdayRewardIssuance $issuance) use (&$meta): void {
                $meta = $this->sourceMetaBuilder->mergeSourceMeta(
                    $meta,
                    is_array($issuance->metadata ?? null) ? $issuance->metadata : []
                );
            });

        CandleCashRedemption::query()
            ->where('external_order_source', 'order')
            ->where('external_order_id', (string) $order->id)
            ->get(['redemption_context'])
            ->each(function (CandleCashRedemption $redemption) use (&$meta): void {
                $meta = $this->sourceMetaBuilder->mergeSourceMeta(
                    $meta,
                    is_array($redemption->redemption_context['attribution_meta'] ?? null)
                        ? $redemption->redemption_context['attribution_meta']
                        : []
                );
            });

        return $meta;
    }

    /**
     * @return array<string,mixed>
     */
    protected function linkMeta(int $profileId, string $sourceType, string $sourceId): array
    {
        if ($profileId <= 0 || $sourceType === '' || $sourceId === '') {
            return [];
        }

        $link = MarketingProfileLink::query()
            ->where('marketing_profile_id', $profileId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first(['source_meta']);

        return is_array($link?->source_meta ?? null) ? $link->source_meta : [];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    protected function semanticSnapshot(array $snapshot): array
    {
        $semantic = $snapshot;
        unset($semantic['captured_at']);

        return $this->sortedArray($semantic);
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    protected function snapshotFields(array $meta): array
    {
        $snapshot = [];

        foreach ($this->fields as $field) {
            if (array_key_exists($field, $meta)) {
                $snapshot[$field] = $meta[$field];
            }
        }

        return $snapshot;
    }

    protected function explicitChannel(?string $channel): ?string
    {
        return match (strtolower(trim((string) $channel))) {
            'sms' => 'text',
            'email' => 'email',
            default => null,
        };
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    /**
     * @param  array<string|int,mixed>  $value
     * @return array<string|int,mixed>
     */
    protected function sortedArray(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortedArray($item);
            }
        }

        ksort($value);

        return $value;
    }
}

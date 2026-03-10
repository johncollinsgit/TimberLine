<?php

namespace App\Services\Marketing;

use App\Models\Order;
use App\Support\Marketing\MarketingIdentityNormalizer;

class MarketingIdentityExtractor
{
    public function __construct(
        protected MarketingIdentityNormalizer $normalizer
    ) {
    }

    /**
     * @param array<string,mixed> $context
     * @return array{
     *   first_name:?string,
     *   last_name:?string,
     *   full_name:?string,
     *   raw_email:?string,
     *   normalized_email:?string,
     *   raw_phone:?string,
     *   normalized_phone:?string,
     *   source_channels:array<int,string>,
     *   source_links:array<int,array{source_type:string,source_id:string,source_meta:array<string,mixed>}>,
     *   primary_source:array{source_type:string,source_id:string},
     *   has_identity:bool
     * }
     */
    public function extractFromOrder(Order $order, array $context = []): array
    {
        $nameCandidate = $this->firstNonEmpty([
            $context['full_name'] ?? null,
            $context['name'] ?? null,
            $this->stringValue($order, 'customer_name'),
            $this->stringValue($order, 'shipping_name'),
            $this->stringValue($order, 'billing_name'),
            $this->stringValue($order, 'order_label'),
            $this->stringValue($order, 'shipping_company'),
            $this->stringValue($order, 'billing_company'),
        ]);

        $firstName = $this->firstNonEmpty([
            $context['first_name'] ?? null,
            $this->stringValue($order, 'first_name'),
        ]);
        $lastName = $this->firstNonEmpty([
            $context['last_name'] ?? null,
            $this->stringValue($order, 'last_name'),
        ]);

        if (($firstName === null || $firstName === '') && ($lastName === null || $lastName === '')) {
            [$splitFirst, $splitLast] = $this->normalizer->splitName($nameCandidate);
            $firstName = $splitFirst;
            $lastName = $splitLast;
        }

        [$rawEmail, $normalizedEmail] = $this->resolveEmail($order, $context);
        [$rawPhone, $normalizedPhone] = $this->resolvePhone($order, $context);

        $sourceLinks = $this->buildSourceLinks($order);
        $sourceChannels = $this->deriveSourceChannels($order, $context);

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $nameCandidate,
            'raw_email' => $rawEmail,
            'normalized_email' => $normalizedEmail,
            'raw_phone' => $rawPhone,
            'normalized_phone' => $normalizedPhone,
            'source_channels' => $sourceChannels,
            'source_links' => $sourceLinks,
            'primary_source' => [
                'source_type' => $sourceLinks[0]['source_type'],
                'source_id' => $sourceLinks[0]['source_id'],
            ],
            'has_identity' => $normalizedEmail !== null || $normalizedPhone !== null,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array{0:?string,1:?string}
     */
    protected function resolveEmail(Order $order, array $context): array
    {
        $candidates = array_filter([
            $context['email'] ?? null,
            $context['customer_email'] ?? null,
            $this->stringValue($order, 'email'),
            $this->stringValue($order, 'customer_email'),
            $this->stringValue($order, 'shipping_email'),
            $this->stringValue($order, 'billing_email'),
        ], fn ($value) => is_string($value) && trim($value) !== '');

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizer->normalizeEmail((string) $candidate);
            if ($normalized !== null) {
                return [trim((string) $candidate), $normalized];
            }
        }

        return [null, null];
    }

    /**
     * @param array<string,mixed> $context
     * @return array{0:?string,1:?string}
     */
    protected function resolvePhone(Order $order, array $context): array
    {
        $candidates = array_filter([
            $context['phone'] ?? null,
            $context['customer_phone'] ?? null,
            $this->stringValue($order, 'phone'),
            $this->stringValue($order, 'customer_phone'),
            $this->stringValue($order, 'shipping_phone'),
            $this->stringValue($order, 'billing_phone'),
        ], fn ($value) => is_string($value) && trim($value) !== '');

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizer->normalizePhone((string) $candidate);
            if ($normalized !== null) {
                return [trim((string) $candidate), $normalized];
            }
        }

        return [null, null];
    }

    /**
     * @return array<int,array{source_type:string,source_id:string,source_meta:array<string,mixed>}>
     */
    protected function buildSourceLinks(Order $order): array
    {
        $links = [];

        $links[] = [
            'source_type' => 'order',
            'source_id' => (string) $order->id,
            'source_meta' => [
                'order_id' => (int) $order->id,
                'order_number' => $order->order_number,
                'order_type' => $order->order_type,
                'source' => $order->source,
                'shopify_store_key' => $order->shopify_store_key,
                'ordered_at' => optional($order->ordered_at)->toIso8601String(),
            ],
        ];

        $shopifyOrderId = $order->shopify_order_id;
        if ($shopifyOrderId !== null && $shopifyOrderId !== '') {
            $storeKey = (string) ($order->shopify_store_key ?: $order->shopify_store ?: 'unknown');
            $links[] = [
                'source_type' => 'shopify_order',
                'source_id' => $storeKey . ':' . $shopifyOrderId,
                'source_meta' => [
                    'order_id' => (int) $order->id,
                    'shopify_store_key' => $storeKey,
                    'shopify_order_id' => (string) $shopifyOrderId,
                    'order_number' => $order->order_number,
                    'ordered_at' => optional($order->ordered_at)->toIso8601String(),
                ],
            ];
        }

        return $links;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,string>
     */
    protected function deriveSourceChannels(Order $order, array $context): array
    {
        $channels = [];
        $source = strtolower(trim((string) ($order->source ?? '')));
        $orderType = strtolower(trim((string) ($order->order_type ?? '')));
        $container = strtolower(trim((string) ($order->container_name ?? '')));

        if ($order->shopify_order_id !== null || str_contains($source, 'shopify')) {
            $channels[] = 'shopify';
            $channels[] = 'online';
        }

        if ($orderType === 'wholesale' || str_contains($source, 'wholesale')) {
            $channels[] = 'wholesale';
        }

        if ($orderType === 'event' || $order->event_id !== null) {
            $channels[] = 'event';
        }

        if (str_contains($source, 'market') || str_contains($container, 'market')) {
            $channels[] = 'market';
        }

        if (in_array($source, ['manual', 'internal'], true)) {
            $channels[] = 'manual_import';
        }

        $contextChannels = $context['source_channels'] ?? [];
        if (is_string($contextChannels) && trim($contextChannels) !== '') {
            $channels[] = strtolower(trim($contextChannels));
        } elseif (is_array($contextChannels)) {
            foreach ($contextChannels as $channel) {
                if (is_string($channel) && trim($channel) !== '') {
                    $channels[] = strtolower(trim($channel));
                }
            }
        }

        return array_values(array_unique(array_filter($channels, fn (string $value) => $value !== '')));
    }

    protected function stringValue(Order $order, string $key): ?string
    {
        $attributes = $order->getAttributes();
        if (!array_key_exists($key, $attributes)) {
            return null;
        }

        $value = trim((string) $attributes[$key]);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<int,mixed> $values
     */
    protected function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $string = trim((string) $value);
            if ($string !== '') {
                return $string;
            }
        }

        return null;
    }
}

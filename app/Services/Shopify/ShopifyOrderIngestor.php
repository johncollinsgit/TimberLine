<?php

namespace App\Services\Shopify;

use App\Jobs\SyncMarketingProfileFromOrder;
use App\Models\MappingException;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\ShopifyImportException;
use App\Models\WholesaleCustomScent;
use App\Services\Marketing\MarketingAttributionSourceMetaBuilder;
use App\Services\Marketing\StorefrontOrderLinkageService;
use App\Services\Shipping\BusinessDayCalculator;
use App\Support\Shopify\InfiniteOptionsParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ShopifyOrderIngestor
{
    public function __construct(
        protected BusinessDayCalculator $calculator,
        protected MarketingAttributionSourceMetaBuilder $attributionSourceMetaBuilder,
        protected StorefrontOrderLinkageService $storefrontOrderLinkageService
    ) {
    }

    /**
     * @param array{key: string, source: string, tenant_id?:?int} $store
     * @param array<string, mixed> $orderData
     * @param array{tenant_id?:?int,dispatch_profile_sync?:bool} $options
     * @return array{
     *   lines_count:int,
     *   merged_lines_count:int,
     *   mapping_exceptions_count:int,
     *   order_id:?int,
     *   storefront_linked?:bool,
     *   storefront_link_confidence?:?float,
     *   storefront_link_method?:?string
     * }
     */
    public function ingest(array $store, array $orderData, array $options = []): array
    {
        $resolvedTenantId = $this->positiveInt($options['tenant_id'] ?? null)
            ?? $this->positiveInt($store['tenant_id'] ?? null);
        $dispatchProfileSync = array_key_exists('dispatch_profile_sync', $options)
            ? (bool) $options['dispatch_profile_sync']
            : true;
        $summary = [
            'lines_count' => 0,
            'merged_lines_count' => 0,
            'mapping_exceptions_count' => 0,
            'order_id' => null,
            'storefront_linked' => false,
            'storefront_link_confidence' => null,
            'storefront_link_method' => null,
        ];

        $noteText = $this->buildOrderNote($orderData);
        $mergedLines = $this->mergeLineItems($orderData['line_items'] ?? [], $noteText, $orderData, $store['key'] ?? null);
        $summary['merged_lines_count'] = max(0, count($orderData['line_items'] ?? []) - count($mergedLines));

        $shopifyOrderId = isset($orderData['id']) ? (int) $orderData['id'] : null;
        if (!$shopifyOrderId) {
            return $summary;
        }

        $syncedOrderId = null;
        $identityContext = [];

        DB::transaction(function () use ($store, $orderData, $mergedLines, $shopifyOrderId, $resolvedTenantId, &$summary, &$syncedOrderId, &$identityContext): void {
            $order = Order::query()
                ->where('shopify_store_key', $store['key'])
                ->where('shopify_order_id', $shopifyOrderId)
                ->first() ?? new Order();

            $payloadAttributionMeta = $this->attributionSourceMetaBuilder->fromShopifyOrderPayload(
                $orderData,
                (string) ($store['key'] ?? '')
            );
            $couponSignals = $this->extractCouponSignals($orderData);
            if ($couponSignals !== []) {
                $payloadAttributionMeta['coupon_signals'] = array_values($couponSignals);
            }
            $financials = $this->extractOrderFinancials($orderData);

            $order->shopify_store_key = $store['key'];
            $order->shopify_order_id = $shopifyOrderId;
            $order->tenant_id = $resolvedTenantId;
            $order->shopify_name = $orderData['name'] ?? null;
            $order->source = $store['source'];
            $orderType = $store['key'] === 'wholesale' ? 'wholesale' : 'retail';
            if ($store['key'] === 'retail' && $this->isWholesaleOrder($orderData)) {
                $orderType = 'wholesale';
            }
            $order->order_type = $orderType;
            $orderedAt = $orderData['created_at'] ?? null;
            $order->ordered_at = $orderedAt;

            $shipBy = null;
            $dueAt = null;
            if (!empty($orderedAt)) {
                $start = CarbonImmutable::parse($orderedAt);
                $daysToAdd = $store['key'] === 'wholesale' ? 10 : 3;
                $shipBy = $this->calculator->addBusinessDays($start, $daysToAdd);
                $dueAt = $this->calculator->subBusinessDays($shipBy, 2);
            }

            $order->due_at = $dueAt;
            $order->ship_by_at = $shipBy;
            $order->shopify_store = $store['key'];

            if (empty($order->order_number)) {
                $order->order_number = $orderData['name'] ?? null;
            }

            if (empty($order->order_label)) {
                $order->order_label = $orderData['shipping_address']['name']
                    ?? $orderData['shipping_address']['company']
                    ?? $orderData['billing_address']['name']
                    ?? $orderData['billing_address']['company']
                    ?? $orderData['customer']['default_address']['name']
                    ?? $orderData['customer']['first_name'] ?? null
                    ?? $orderData['name'] ?? null
                    ?? $orderData['order_number'] ?? null;
            }

            $order->shipping_name = $orderData['shipping_address']['name'] ?? null;
            $order->billing_name = $orderData['billing_address']['name'] ?? null;
            $order->shipping_company = $orderData['shipping_address']['company'] ?? null;
            $order->shipping_address1 = $orderData['shipping_address']['address1'] ?? null;
            $order->billing_company = $orderData['billing_address']['company'] ?? null;
            $order->billing_address1 = $orderData['billing_address']['address1'] ?? null;
            $order->shopify_customer_id = isset($orderData['customer']['id']) && $orderData['customer']['id'] !== null
                ? (string) $orderData['customer']['id']
                : null;
            $order->first_name = $orderData['customer']['first_name'] ?? null;
            $order->last_name = $orderData['customer']['last_name'] ?? null;
            $order->email = $orderData['email'] ?? null;
            $order->phone = $orderData['phone'] ?? null;
            $order->customer_email = $orderData['customer']['email'] ?? null;
            $order->customer_phone = $orderData['customer']['phone'] ?? null;
            $order->shipping_email = $orderData['shipping_address']['email'] ?? ($orderData['email'] ?? null);
            $order->shipping_phone = $orderData['shipping_address']['phone'] ?? null;
            $order->billing_email = $orderData['billing_address']['email'] ?? ($orderData['email'] ?? null);
            $order->billing_phone = $orderData['billing_address']['phone'] ?? null;
            $order->currency_code = $financials['currency_code'];
            $order->subtotal_price = $financials['subtotal_price'];
            $order->discount_total = $financials['discount_total'];
            $order->tax_total = $financials['tax_total'];
            $order->shipping_total = $financials['shipping_total'];
            $order->refund_total = $financials['refund_total'];
            $order->total_price = $financials['total_price'];
            $order->attribution_meta = $this->attributionSourceMetaBuilder->mergeSourceMeta(
                is_array($order->attribution_meta ?? null) ? $order->attribution_meta : [],
                $payloadAttributionMeta
            );

            if (empty($order->status)) {
                $order->status = 'new';
            }

            $order->save();
            $syncedOrderId = (int) $order->id;
            $summary['order_id'] = $syncedOrderId;
            $identityContext = $this->buildMarketingIdentityContext(
                $orderData,
                (string) ($store['key'] ?? ''),
                $resolvedTenantId
            );
            $storefrontLink = $this->storefrontOrderLinkageService->linkOrder(
                $order,
                $orderData,
                [
                    'tenant_id' => $resolvedTenantId,
                    'store_key' => (string) ($store['key'] ?? ''),
                ]
            );
            $summary['storefront_linked'] = (bool) ($storefrontLink['linked'] ?? false);
            $summary['storefront_link_confidence'] = isset($storefrontLink['confidence'])
                ? (float) $storefrontLink['confidence']
                : null;
            $summary['storefront_link_method'] = isset($storefrontLink['method'])
                ? (string) $storefrontLink['method']
                : null;

            foreach ($mergedLines as $line) {
                $lineModel = $this->upsertLine($order->id, $line);
                $summary['lines_count']++;

                $missingScent = empty($lineModel->scent_id);
                $missingSize = empty($lineModel->size_id);
                if ($missingScent || $missingSize) {
                    $reason = $missingScent ? 'unmapped_scent' : 'unmapped_size';
                    if (!empty($line['payload']['candle_club'])) {
                        $reason = 'candle_club';
                    }
                    if ($missingScent && ($order->order_type === 'wholesale' || $order->shopify_store_key === 'wholesale')) {
                        $reason = 'wholesale_custom_unmapped';
                    }
                    $mapping = MappingException::updateOrCreate(
                        [
                            'store_key' => $store['key'],
                            'order_line_id' => $lineModel->id,
                        ],
                        [
                            'shopify_order_id' => $shopifyOrderId,
                            'account_name' => $line['account_name'] ?? null,
                            'raw_scent_name' => $line['raw_scent_name'] ?? null,
                            'shopify_line_item_id' => $line['line_item_id'],
                            'order_id' => $order->id,
                            'raw_title' => $line['title'],
                            'raw_variant' => $line['variant'],
                            'sku' => $line['sku'],
                            'reason' => $reason,
                            'payload_json' => $line['payload'],
                        ]
                    );

                    if ($mapping->wasRecentlyCreated) {
                        $summary['mapping_exceptions_count']++;
                    }
                    if ($order->requires_shipping_review !== true) {
                        $order->requires_shipping_review = true;
                        $order->save();
                    }
                }
            }
        });

        if ($syncedOrderId && $dispatchProfileSync) {
            SyncMarketingProfileFromOrder::dispatch(
                $syncedOrderId,
                $identityContext,
                $resolvedTenantId
            )->afterCommit();
        }

        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     * @param array<string, mixed> $orderData
     * @return array<int, array{sku: ?string, title: ?string, variant: ?string, quantity: int, line_item_id: ?int, wick_type: string, image_url: ?string, raw_title: ?string, raw_variant: ?string, scent_id: ?int, size_id?: ?int, external_key?: ?string, shopify_product_id?: ?int, shopify_variant_id?: ?int, currency_code?: ?string, unit_price?: ?float, line_subtotal?: ?float, discount_total?: ?float, line_total?: ?float, payload: array<string, mixed>}>
     */
    public function mergeLineItems(array $lineItems, ?string $orderNote = null, array $orderData = [], ?string $storeKey = null): array
    {
        $merged = [];
        $orderNoteWick = $this->detectWickTypeFromText($orderNote) ?? 'cotton';
        $scentIndex = $this->scentIndex();
        $sizeIndex = $this->sizeIndex();
        $parser = new InfiniteOptionsParser();

        foreach ($lineItems as $line) {
            if ($this->isNonPourLineItem($line)) {
                continue;
            }
            $isCandleClub = $this->isCandleClubLineItem($line);
            if ($this->isBundleLineItem($line)) {
                $bundleLines = $this->expandBundleLineItem($line, $parser, $scentIndex, $storeKey, $orderData);
                foreach ($bundleLines as $bundleLine) {
                    $key = implode('|', [
                        (string) ($bundleLine['sku'] ?? ''),
                        (string) ($bundleLine['title'] ?? ''),
                        (string) ($bundleLine['variant'] ?? ''),
                        (string) ($bundleLine['wick_type'] ?? ''),
                        (string) ($bundleLine['external_key'] ?? ''),
                    ]);
                    if (!isset($merged[$key])) {
                        $merged[$key] = $bundleLine;
                        $merged[$key]['quantity'] = 0;
                    }
                    $merged[$key]['quantity'] += (int) ($bundleLine['quantity'] ?? 0);
                }
                continue;
            }

            $properties = is_array($line['properties'] ?? null) ? $line['properties'] : [];
            $propertyScent = $this->extractPropertyValue($properties, [
                'scent',
                'scent name',
                'fragrance',
                'fragrance name',
            ]);
            $titleRaw = $line['title'] ?? null;
            $variantRaw = $line['variant_title'] ?? null;
            $titleForScent = $this->shouldPreferPropertyScent($titleRaw) ? $propertyScent : $titleRaw;

            $scentName = $this->normalizeScentName($titleForScent);
            if (!$scentName && $propertyScent) {
                $scentName = $this->normalizeScentName($propertyScent);
            }
            if (!$propertyScent && $this->shouldUseVariantAsScentSource($titleRaw)) {
                $variantScent = $this->normalizeScentName($variantRaw);
                if ($variantScent) {
                    $scentName = $variantScent;
                }
            }
            $scentName = $this->bestGuessScent($scentName, $scentIndex);

            $rawScentName = $propertyScent ?: $titleRaw;
            if (!$propertyScent && $this->shouldUseVariantAsScentSource($titleRaw)) {
                $variantScent = $this->normalizeScentName($variantRaw);
                if ($variantScent) {
                    $rawScentName = $variantScent;
                }
            }
            if (!$rawScentName && $scentName) {
                $rawScentName = $scentName;
            }
            $rawScentNameClean = $this->normalizeScentName($rawScentName) ?? $rawScentName;
            if ($rawScentName && $rawScentNameClean && $rawScentNameClean !== $rawScentName) {
                $this->recordNormalization($storeKey, $orderData, $line, 'scent', $rawScentName, $rawScentNameClean);
            }

            $resolved = $this->resolveScentForOrder($scentName, $scentIndex, $storeKey, $orderData, $rawScentNameClean);
            $scentId = $resolved['scent_id'] ?? null;
            $resolvedName = $resolved['resolved_name'] ?? $scentName;
            $accountName = $resolved['account_name'] ?? null;
            $rawScentName = $resolved['raw_scent_name'] ?? $rawScentNameClean;
            $lineWick = $this->extractWickTypeFromProperties($properties)
                ?? $this->detectWickTypeFromText($variantRaw)
                ?? $this->detectWickTypeFromText($line['notes'] ?? null)
                ?? $orderNoteWick
                ?? 'cotton';
            $sku = $line['sku'] ?? null;
            $title = $resolvedName ?: ($scentName ?: $this->normalizeScentName($titleRaw));
            $variant = $variantRaw;
            $quantity = (int) ($line['quantity'] ?? 0);
            $lineItemId = isset($line['id']) ? (int) $line['id'] : null;
            $imageUrl = null;
            if (is_array($line['image'] ?? null)) {
                $imageUrl = $line['image']['src'] ?? null;
            } elseif (is_string($line['image'] ?? null)) {
                $imageUrl = $line['image'];
            } elseif (is_string($line['image_url'] ?? null)) {
                $imageUrl = $line['image_url'];
            }

            $key = implode('|', [
                (string) ($sku ?? ''),
                (string) ($title ?? ''),
                (string) ($variant ?? ''),
                (string) ($lineWick ?? ''),
            ]);
            $lineFinancials = $this->extractLineFinancials($line);
            $isNew = !isset($merged[$key]);

            if ($isNew) {
                $sizeId = null;
                if (!empty($variantRaw)) {
                    $normalizedVariant = $this->normalizeSize((string) $variantRaw);
                    $sizeId = $sizeIndex[$normalizedVariant] ?? null;
                }

                if (!$sizeId) {
                    $baseSize = $this->detectSizeFromText((string) ($variantRaw ?? ''))
                        ?: $this->detectSizeFromText((string) ($titleRaw ?? ''));
                    if ($baseSize) {
                        // Non-wick sizes (wax melts / room sprays) should match directly.
                        $normalizedBase = $this->normalizeSize($baseSize);
                        if (!empty($variantRaw) && $normalizedBase !== $this->normalizeSize((string) $variantRaw)) {
                            $this->recordNormalization($storeKey, $orderData, $line, 'size', (string) $variantRaw, $baseSize);
                        } elseif (!empty($titleRaw) && $normalizedBase !== $this->normalizeSize((string) $titleRaw)) {
                            $this->recordNormalization($storeKey, $orderData, $line, 'size', (string) $titleRaw, $baseSize);
                        }
                        $sizeId = $sizeIndex[$normalizedBase] ?? null;
                    }
                    if (!$sizeId) {
                        $wick = $lineWick === 'wood' ? 'cedar' : $lineWick;
                        if ($baseSize && $wick) {
                            $candidate = $baseSize . ' ' . $wick . ' wick';
                            $normalizedCandidate = $this->normalizeSize($candidate);
                            if (!empty($variantRaw) && $normalizedCandidate !== $this->normalizeSize((string) $variantRaw)) {
                                $this->recordNormalization($storeKey, $orderData, $line, 'size', (string) $variantRaw, $candidate);
                            }
                            $sizeId = $sizeIndex[$normalizedCandidate] ?? null;
                        }
                    }
                }
                $payload = $line;
                if ($scentName) {
                    $payload['properties_scent'] = $scentName;
                }
                if ($lineWick) {
                    $payload['properties_wick'] = $lineWick;
                }
                if ($imageUrl) {
                    $payload['image_url'] = $imageUrl;
                }
                if ($titleRaw) {
                    $payload['raw_title'] = $titleRaw;
                }
                if ($variantRaw) {
                    $payload['raw_variant'] = $variantRaw;
                }
                if ($rawScentName) {
                    $payload['raw_scent_name'] = $rawScentName;
                }
                if ($isCandleClub) {
                    $payload['candle_club'] = true;
                    $scentId = null; // force exception + prompt
                }
                if ($rawScentNameClean && $rawScentNameClean !== $rawScentName) {
                    $payload['raw_scent_name_clean'] = $rawScentNameClean;
                }
                if ($accountName) {
                    $payload['account_name'] = $accountName;
                }

                $merged[$key] = [
                    'sku' => $sku,
                    'title' => $title,
                    'variant' => $variant,
                    'line_item_id' => $lineItemId,
                    'quantity' => 0,
                    'wick_type' => $lineWick,
                    'image_url' => $imageUrl,
                    'raw_title' => $titleRaw,
                    'raw_variant' => $variantRaw,
                    'scent_id' => $scentId,
                    'size_id' => $sizeId,
                    'raw_scent_name' => $rawScentName,
                    'account_name' => $accountName,
                    'shopify_product_id' => $lineFinancials['shopify_product_id'],
                    'shopify_variant_id' => $lineFinancials['shopify_variant_id'],
                    'currency_code' => $lineFinancials['currency_code'],
                    'unit_price' => $lineFinancials['unit_price'],
                    'line_subtotal' => $lineFinancials['line_subtotal'],
                    'discount_total' => $lineFinancials['discount_total'],
                    'line_total' => $lineFinancials['line_total'],
                    'payload' => $payload,
                ];
            }

            $merged[$key]['quantity'] += $quantity;
            if ($merged[$key]['line_item_id'] !== $lineItemId) {
                $merged[$key]['line_item_id'] = null;
            }
            if (empty($merged[$key]['image_url']) && $imageUrl) {
                $merged[$key]['image_url'] = $imageUrl;
            }
            if (! $isNew) {
                if (($merged[$key]['shopify_product_id'] ?? null) !== ($lineFinancials['shopify_product_id'] ?? null)) {
                    $merged[$key]['shopify_product_id'] = null;
                }
                if (($merged[$key]['shopify_variant_id'] ?? null) !== ($lineFinancials['shopify_variant_id'] ?? null)) {
                    $merged[$key]['shopify_variant_id'] = null;
                }
                if (empty($merged[$key]['currency_code']) && ! empty($lineFinancials['currency_code'])) {
                    $merged[$key]['currency_code'] = $lineFinancials['currency_code'];
                }
                foreach (['line_subtotal', 'discount_total', 'line_total'] as $moneyField) {
                    $merged[$key][$moneyField] = $this->sumMoney(
                        $merged[$key][$moneyField] ?? null,
                        $lineFinancials[$moneyField] ?? null
                    );
                }
            }
            $merged[$key]['unit_price'] = $this->deriveUnitPrice(
                $merged[$key]['line_total'] ?? null,
                $merged[$key]['line_subtotal'] ?? null,
                (int) $merged[$key]['quantity'],
                $lineFinancials['unit_price'] ?? null
            );
        }

        return array_values($merged);
    }

    /**
     * Skip non-pour add-ons (e.g., custom label fees).
     *
     * @param array<string, mixed> $line
     */
    protected function isNonPourLineItem(array $line): bool
    {
        $title = strtolower((string) ($line['title'] ?? ''));
        $variant = strtolower((string) ($line['variant_title'] ?? ''));
        $productType = strtolower((string) ($line['product_type'] ?? ''));
        $haystack = trim($title . ' ' . $variant . ' ' . $productType);

        return str_contains($haystack, 'custom label');
    }

    /**
     * @param array<string, mixed> $line
     */
    protected function isCandleClubLineItem(array $line): bool
    {
        $title = strtolower((string) ($line['title'] ?? ''));
        $productType = strtolower((string) ($line['product_type'] ?? ''));
        return str_contains($title, 'candle club') || str_contains($productType, 'candle club');
    }

    /**
     * @param array<string, mixed> $line
     */
    protected function isBundleLineItem(array $line): bool
    {
        $title = strtolower((string) ($line['title'] ?? ''));
        $productType = strtolower((string) ($line['product_type'] ?? ''));
        return str_contains($title, 'bundle') || str_contains($productType, 'bundle');
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, int> $scentIndex
     * @return array<int, array<string, mixed>>
     */
    protected function expandBundleLineItem(array $line, InfiniteOptionsParser $parser, array $scentIndex, ?string $storeKey, array $orderData): array
    {
        $config = $this->bundleConfigForLine($line);
        if (!$config) {
            $this->recordImportException($storeKey, $orderData, $line, 'bundle_mapping_missing');
            return [];
        }

        $sizeKey = $this->resolveBundleSizeKey($config['size_key'] ?? null, $line);
        $sizeId = $this->resolveSizeId($sizeKey);
        if (!$sizeId) {
            $this->recordImportException($storeKey, $orderData, $line, 'bundle_size_missing');
            return [];
        }

        $selections = $parser->parseBundleSelections($line);
        if (empty($selections)) {
            $this->recordImportException($storeKey, $orderData, $line, 'bundle_no_scent_selections');
            return [];
        }

        $qtyPer = (int) ($config['qty_per_scent'] ?? 1);
        $bundleQty = (int) ($line['quantity'] ?? 1);
        $lineItemId = isset($line['id']) ? (int) $line['id'] : null;
        $imageUrl = null;
        if (is_array($line['image'] ?? null)) {
            $imageUrl = $line['image']['src'] ?? null;
        } elseif (is_string($line['image'] ?? null)) {
            $imageUrl = $line['image'];
        } elseif (is_string($line['image_url'] ?? null)) {
            $imageUrl = $line['image_url'];
        }

        $lines = [];
        foreach ($selections as $selection) {
            $name = $this->normalizeScentName($selection['scent_name'] ?? null);
            if (!$name) {
                $this->recordImportException($storeKey, $orderData, $line, 'bundle_blank_scent', $selection);
                continue;
            }

            $resolvedName = $this->bestGuessScent($name, $scentIndex);
            $rawSelection = $selection['scent_name'] ?? $name;
            $rawSelectionClean = $this->normalizeScentName($rawSelection) ?? $rawSelection;
            $resolved = $this->resolveScentForOrder($resolvedName, $scentIndex, $storeKey, $orderData, $rawSelectionClean);
            $scentId = $resolved['scent_id'] ?? null;
            $resolvedName = $resolved['resolved_name'] ?? $resolvedName;
            if (!$scentId) {
                $this->recordImportException($storeKey, $orderData, $line, 'bundle_scent_not_found', $selection);
                continue;
            }

            $slot = (int) ($selection['slot'] ?? 0);
            $externalKey = $lineItemId ? $lineItemId . ':' . $slot : null;

            $lines[] = [
                'sku' => $line['sku'] ?? null,
                'title' => $resolvedName,
                'variant' => $sizeKey ?? null,
                // Bundle expansions must not reuse the parent line_item_id (unique constraint).
                // We keep traceability via external_key (line_item_id:slot).
                'line_item_id' => null,
                'quantity' => $qtyPer * $bundleQty,
                'wick_type' => $this->extractWickTypeFromProperties(is_array($line['properties'] ?? null) ? $line['properties'] : [])
                    ?? $this->detectWickTypeFromText($line['variant_title'] ?? null)
                    ?? 'cotton',
                'image_url' => $imageUrl,
                'raw_title' => $resolvedName,
                'raw_variant' => $sizeKey ?? null,
                'scent_id' => $scentId,
                'raw_scent_name' => $resolved['raw_scent_name'] ?? $rawSelectionClean,
                'account_name' => $resolved['account_name'] ?? null,
                'size_id' => $sizeId,
                'external_key' => $externalKey,
                'payload' => [
                    'bundle_title' => $line['title'] ?? null,
                    'bundle_properties' => $line['properties'] ?? [],
                    'selection' => $selection,
                    'bundle_size_key' => $sizeKey,
                ],
            ];
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>|null
     */
    protected function bundleConfigForLine(array $line): ?array
    {
        $map = config('shopify_bundles', []);
        $title = strtolower((string) ($line['title'] ?? ''));
        $productType = strtolower((string) ($line['product_type'] ?? ''));

        $haystack = $this->normalizeBundleKey($title . ' ' . $productType);
        foreach ($map as $key => $value) {
            $needle = $this->normalizeBundleKey((string) $key);
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return $value;
            }
        }
        return null;
    }

    protected function normalizeBundleKey(string $value): string
    {
        $lower = strtolower($value);
        $lower = preg_replace('/[^a-z0-9]+/i', ' ', $lower) ?? $lower;
        return trim(preg_replace('/\s+/', ' ', $lower) ?? $lower);
    }

    protected function resolveSizeId(?string $sizeKey): ?int
    {
        if (!$sizeKey) {
            return null;
        }
        $size = \App\Models\Size::query()
            ->where('code', $sizeKey)
            ->orWhere('label', $sizeKey)
            ->first();
        return $size?->id;
    }

    /**
     * @param array<string, mixed> $line
     */
    protected function resolveBundleSizeKey(?string $sizeKey, array $line): ?string
    {
        $title = strtolower((string) ($line['title'] ?? ''));
        $variantTitle = strtolower((string) ($line['variant_title'] ?? ''));

        $baseSize = null;
        if (str_contains($title, '16oz') || str_contains($title, '16 oz')) {
            $baseSize = '16oz';
        } elseif (str_contains($title, '8oz') || str_contains($title, '8 oz')) {
            $baseSize = '8oz';
        } elseif (str_contains($title, '4oz') || str_contains($title, '4 oz')) {
            $baseSize = '4oz';
        }

        $wick = null;
        if (str_contains($variantTitle, 'cotton')) {
            $wick = 'cotton';
        } elseif (str_contains($variantTitle, 'cedar') || str_contains($variantTitle, 'wood')) {
            $wick = 'cedar';
        }

        if ($baseSize && $wick) {
            return $baseSize . '-' . $wick;
        }

        if ($sizeKey && $wick) {
            if (str_contains($sizeKey, '16oz')) {
                return $wick === 'cedar' ? '16oz-cedar' : '16oz-cotton';
            }
            if (str_contains($sizeKey, '8oz')) {
                return $wick === 'cedar' ? '8oz-cedar' : '8oz-cotton';
            }
        }

        return $sizeKey;
    }

    protected function recordImportException(?string $storeKey, array $orderData, array $line, string $reason, array $extra = []): void
    {
        ShopifyImportException::query()->create([
            'shop' => $storeKey,
            'shopify_order_id' => $orderData['id'] ?? null,
            'shopify_line_item_id' => $line['id'] ?? null,
            'title' => $line['title'] ?? null,
            'reason' => $reason,
            'payload' => array_merge([
                'properties' => $line['properties'] ?? null,
                'line_item' => $line,
            ], $extra),
        ]);
    }

    protected function recordNormalization(?string $storeKey, array $orderData, array $line, string $field, string $raw, string $normalized): void
    {
        if (trim($raw) === '' || trim($normalized) === '' || trim($raw) === trim($normalized)) {
            return;
        }

        \App\Models\ImportNormalization::query()->create([
            'store_key' => $storeKey,
            'shopify_order_id' => $orderData['id'] ?? null,
            'shopify_line_item_id' => $line['id'] ?? null,
            'order_id' => null,
            'field' => $field,
            'raw_value' => $raw,
            'normalized_value' => $normalized,
            'context_json' => [
                'title' => $line['title'] ?? null,
                'variant' => $line['variant_title'] ?? null,
                'properties' => $line['properties'] ?? null,
            ],
        ]);
    }

    /**
     * @param array<string, int> $scentIndex
     * @return array{resolved_name:?string,scent_id:?int,account_name:?string,raw_scent_name:?string}
     */
    protected function resolveScentForOrder(?string $scentName, array $scentIndex, ?string $storeKey, array $orderData, ?string $rawScentName): array
    {
        $resolvedName = $scentName;
        $scentId = $scentName && isset($scentIndex[$scentName]) ? $scentIndex[$scentName] : null;
        $accountName = null;

        if ($scentId) {
            return [
                'resolved_name' => $resolvedName,
                'scent_id' => $scentId,
                'account_name' => null,
                'raw_scent_name' => $rawScentName,
            ];
        }

        $isWholesale = $storeKey === 'wholesale' || $this->isWholesaleOrder($orderData);
        if (!$isWholesale) {
            return [
                'resolved_name' => $resolvedName,
                'scent_id' => null,
                'account_name' => null,
                'raw_scent_name' => $rawScentName,
            ];
        }

        $accountName = $this->resolveWholesaleAccountName($orderData);
        $normalizedAccount = WholesaleCustomScent::normalizeAccountName($accountName);
        $normalizedScent = WholesaleCustomScent::normalizeScentName($rawScentName ?? $scentName ?? '');

        if ($normalizedAccount !== '' && $normalizedScent !== '') {
            $candidates = WholesaleCustomScent::query()
                ->whereRaw('lower(account_name) = ?', [mb_strtolower($normalizedAccount)])
                ->get();

            $matched = $candidates->first(function (WholesaleCustomScent $row) use ($normalizedScent) {
                return WholesaleCustomScent::normalizeScentName($row->custom_scent_name) === $normalizedScent;
            });

            if ($matched && $matched->canonical_scent_id) {
                $resolvedName = $this->bestGuessScent($scentName, $scentIndex);
                return [
                    'resolved_name' => $resolvedName,
                    'scent_id' => $matched->canonical_scent_id,
                    'account_name' => $accountName,
                    'raw_scent_name' => $rawScentName,
                ];
            }

            if (!$matched && $candidates->isNotEmpty()) {
                $best = null;
                $bestScore = 0.0;
                foreach ($candidates as $candidate) {
                    $candidateName = WholesaleCustomScent::normalizeScentName($candidate->custom_scent_name);
                    if ($candidateName === '') {
                        continue;
                    }
                    $score = max(
                        similar_text($normalizedScent, $candidateName) / max(1, max(strlen($normalizedScent), strlen($candidateName))),
                        1 - (levenshtein($normalizedScent, $candidateName) / max(1, max(strlen($normalizedScent), strlen($candidateName))))
                    );
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $candidate;
                    }
                }

                if ($best && $bestScore >= 0.86 && $best->canonical_scent_id) {
                    $resolvedName = $this->bestGuessScent($scentName, $scentIndex);
                    return [
                        'resolved_name' => $resolvedName,
                        'scent_id' => $best->canonical_scent_id,
                        'account_name' => $accountName,
                        'raw_scent_name' => $rawScentName,
                    ];
                }
            }
        }

        return [
            'resolved_name' => $resolvedName,
            'scent_id' => null,
            'account_name' => $accountName,
            'raw_scent_name' => $rawScentName,
        ];
    }

    protected function resolveWholesaleAccountName(array $orderData): ?string
    {
        $candidates = [
            $orderData['shipping_address']['company'] ?? null,
            $orderData['billing_address']['company'] ?? null,
            $orderData['shipping_address']['name'] ?? null,
            $orderData['billing_address']['name'] ?? null,
            $orderData['customer']['default_address']['company'] ?? null,
            $orderData['customer']['default_address']['name'] ?? null,
            $orderData['customer']['first_name'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $properties
     * @param array<int, string> $keys
     */
    protected function extractPropertyValue(array $properties, array $keys): ?string
    {
        $targets = array_map(fn (string $k) => strtolower(trim($k)), $keys);

        foreach ($properties as $property) {
            $name = strtolower(trim((string) ($property['name'] ?? '')));
            if (!$name || !in_array($name, $targets, true)) {
                continue;
            }

            $value = trim((string) ($property['value'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function normalizeScentName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim($value);
        if ($clean === '') {
            return null;
        }

        $clean = preg_replace('/\b(wholesale|retail|market|event)\b\s*/i', '', $clean);
        $clean = str_replace('&', 'and', $clean);
        $clean = preg_replace('/\b(wood|cotton)\s*wick\b/i', '', $clean);
        $clean = preg_replace('/\b(\d+)\s?oz\b/i', '', $clean);
        $clean = preg_replace('/\b(tin|jar)\b/i', '', $clean);
        $clean = trim($clean);
        $clean = preg_replace('/\s{2,}/', ' ', $clean);

        return $clean === '' ? null : $clean;
    }

    /**
     * @param array<string, mixed> $orderData
     */
    protected function buildOrderNote(array $orderData): ?string
    {
        $parts = [];

        $note = trim((string) ($orderData['note'] ?? ''));
        if ($note !== '') {
            $parts[] = $note;
        }

        $noteAttributes = $orderData['note_attributes'] ?? [];
        if (is_array($noteAttributes)) {
            foreach ($noteAttributes as $attr) {
                $name = trim((string) ($attr['name'] ?? ''));
                $value = trim((string) ($attr['value'] ?? ''));
                if ($name !== '' && $value !== '') {
                    $parts[] = "{$name}: {$value}";
                } elseif ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        if (empty($parts)) {
            return null;
        }

        return implode(' | ', $parts);
    }

    /**
     * @param array<int, array<string, mixed>> $properties
     */
    protected function extractWickTypeFromProperties(array $properties): ?string
    {
        $value = $this->extractPropertyValue($properties, [
            'wick',
            'wick type',
            'wood wick',
            'woodwick',
            'cotton wick',
        ]);

        return $this->detectWickTypeFromText($value);
    }

    protected function detectWickTypeFromText(?string $text): ?string
    {
        if (!$text) {
            return null;
        }

        $value = strtolower($text);

        if (str_contains($value, 'wood')) {
            return 'wood';
        }

        if (str_contains($value, 'cotton')) {
            return 'cotton';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $orderData
     */
    protected function isWholesaleOrder(array $orderData): bool
    {
        $tags = strtolower((string) ($orderData['tags'] ?? ''));
        if ($tags !== '') {
            $tagList = array_map('trim', explode(',', $tags));
            foreach ($tagList as $tag) {
                if ($tag === 'wholesale' || $tag === 'wholesale order') {
                    return true;
                }
            }
        }

        $lines = $orderData['line_items'] ?? [];
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $title = strtolower((string) ($line['title'] ?? ''));
                $productType = strtolower((string) ($line['product_type'] ?? ''));
                if (str_contains($title, 'wholesale') || str_contains($productType, 'wholesale')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array{sku: ?string, title: ?string, variant: ?string, quantity: int, line_item_id: ?int, wick_type?: string, image_url?: ?string, raw_title?: ?string, raw_variant?: ?string, scent_id?: ?int, size_id?: ?int, external_key?: ?string, shopify_product_id?: ?int, shopify_variant_id?: ?int, currency_code?: ?string, unit_price?: ?float, line_subtotal?: ?float, discount_total?: ?float, line_total?: ?float} $line
     */
    protected function upsertLine(int $orderId, array $line): OrderLine
    {
        $updateAttributes = [
            'ordered_qty' => $line['quantity'],
            'quantity' => $line['quantity'],
            'sku' => $line['sku'],
            'shopify_product_id' => $line['shopify_product_id'] ?? null,
            'shopify_variant_id' => $line['shopify_variant_id'] ?? null,
            'currency_code' => $line['currency_code'] ?? null,
            'unit_price' => $line['unit_price'] ?? null,
            'line_subtotal' => $line['line_subtotal'] ?? null,
            'discount_total' => $line['discount_total'] ?? null,
            'line_total' => $line['line_total'] ?? null,
            'raw_title' => $line['raw_title'] ?? $line['title'],
            'raw_variant' => $line['raw_variant'] ?? $line['variant'],
            'image_url' => $line['image_url'] ?? null,
            'wick_type' => $line['wick_type'] ?? 'cotton',
            'external_key' => $line['external_key'] ?? null,
        ];

        if (!empty($line['external_key'])) {
            $existing = OrderLine::query()
                ->where('order_id', $orderId)
                ->where('external_key', $line['external_key'])
                ->first();
        } elseif (!empty($line['line_item_id'])) {
            $existing = OrderLine::query()
                ->where('order_id', $orderId)
                ->where('shopify_line_item_id', $line['line_item_id'])
                ->first();
        } else {
            $existing = OrderLine::query()
                ->where('order_id', $orderId)
                ->whereNull('shopify_line_item_id')
                ->where('sku', $line['sku'])
                ->where('raw_title', $line['title'])
                ->where('raw_variant', $line['variant'])
                ->where(function ($q) use ($line) {
                    $wick = $line['wick_type'] ?? 'cotton';
                    if ($wick === 'cotton') {
                        $q->whereNull('wick_type')->orWhere('wick_type', 'cotton');
                    } else {
                        $q->where('wick_type', $wick);
                    }
                })
                ->first();
        }

        if (!$existing && !empty($line['scent_id']) && !empty($line['size_id'])) {
            // Enforce unique (order_id, scent_id, size_id) by merging into existing row.
            $existing = OrderLine::query()
                ->where('order_id', $orderId)
                ->where('scent_id', $line['scent_id'])
                ->where('size_id', $line['size_id'])
                ->first();
        }

        if ($existing) {
            $existing->fill($updateAttributes);
            if (empty($existing->scent_id) && !empty($line['scent_id'])) {
                $existing->scent_id = $line['scent_id'];
            }
            if (empty($existing->size_id) && !empty($line['size_id'])) {
                $existing->size_id = $line['size_id'];
            }
            if ($existing->extra_qty === null) {
                $existing->extra_qty = 0;
            }
            $existing->save();
            return $existing;
        }

        return OrderLine::create([
            'order_id' => $orderId,
            'shopify_line_item_id' => $line['line_item_id'],
            'shopify_product_id' => $line['shopify_product_id'] ?? null,
            'shopify_variant_id' => $line['shopify_variant_id'] ?? null,
            'external_key' => $line['external_key'] ?? null,
            'ordered_qty' => $line['quantity'],
            'extra_qty' => 0,
            'quantity' => $line['quantity'],
            'sku' => $line['sku'],
            'currency_code' => $line['currency_code'] ?? null,
            'unit_price' => $line['unit_price'] ?? null,
            'line_subtotal' => $line['line_subtotal'] ?? null,
            'discount_total' => $line['discount_total'] ?? null,
            'line_total' => $line['line_total'] ?? null,
            'raw_title' => $line['raw_title'] ?? $line['title'],
            'raw_variant' => $line['raw_variant'] ?? $line['variant'],
            'image_url' => $line['image_url'] ?? null,
            'wick_type' => $line['wick_type'] ?? 'cotton',
            'scent_id' => $line['scent_id'] ?? null,
            'size_id' => $line['size_id'] ?? null,
        ]);
    }

    protected function shouldPreferPropertyScent(?string $title): bool
    {
        if (!$title) return false;
        $value = strtolower($title);
        return str_contains($value, '3 for')
            || str_contains($value, '12 for')
            || str_contains($value, 'pick 5')
            || str_contains($value, 'pick five')
            || str_contains($value, 'bundle');
    }

    protected function shouldUseVariantAsScentSource(?string $title): bool
    {
        if (! $title) {
            return false;
        }

        $value = mb_strtolower(trim($title));
        if ($value === '') {
            return false;
        }

        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return preg_match('/\b(sale candles?|custom scents?|house blends?)\b/u', $value) === 1;
    }

    /**
     * @return array<string, int> map normalized name => id
     */
    protected function scentIndex(): array
    {
        return Scent::query()
            ->select(['id', 'display_name', 'name', 'abbreviation'])
            ->get()
            ->mapWithKeys(function (Scent $scent) {
                $names = array_filter([
                    $scent->display_name,
                    $scent->name,
                    $scent->abbreviation,
                ]);
                $keys = [];
                foreach ($names as $n) {
                    $keys[$this->normalizeScentName($n) ?? ''] = $scent->id;
                }
                return $keys;
            })
            ->filter(fn ($id, $key) => $key !== '')
            ->all();
    }

    /**
     * @return array<string, int> map normalized size label/code => id
     */
    protected function sizeIndex(): array
    {
        return \App\Models\Size::query()
            ->select(['id', 'code', 'label'])
            ->get()
            ->mapWithKeys(function (\App\Models\Size $size) {
                $keys = [];
                $code = $size->code ?? '';
                $label = $size->label ?? '';
                if ($code !== '') {
                    $keys[$this->normalizeSize($code)] = $size->id;
                }
                if ($label !== '') {
                    $keys[$this->normalizeSize($label)] = $size->id;
                }
                return $keys;
            })
            ->all();
    }

    protected function normalizeSize(string $value): string
    {
        $lower = strtolower($value);
        $lower = str_replace([' ', '-', '_'], '', $lower);
        $lower = str_replace(['ounces', 'ounce'], 'oz', $lower);
        $lower = str_replace('o z', 'oz', $lower);
        $lower = preg_replace('/[^a-z0-9]+/i', '', $lower) ?? '';
        if ($lower === 'waxmelt') {
            $lower = 'waxmelts';
        }
        if ($lower === 'roomspray') {
            $lower = 'roomsprays';
        }
        return $lower;
    }

    protected function detectSizeFromText(string $value): ?string
    {
        $lower = strtolower($value);
        if (str_contains($lower, '16oz') || str_contains($lower, '16 oz')) {
            return '16oz';
        }
        if (str_contains($lower, '8oz') || str_contains($lower, '8 oz')) {
            return '8oz';
        }
        if (str_contains($lower, '4oz') || str_contains($lower, '4 oz')) {
            return '4oz';
        }
        if (str_contains($lower, 'wax melt')) {
            return 'wax melts';
        }
        if (str_contains($lower, 'room spray')) {
            return 'room sprays';
        }
        return null;
    }

    protected function bestGuessScent(?string $value, array $index): ?string
    {
        if (!$value) return null;
        $needle = $this->normalizeScentName($value);
        if (!$needle) return null;

        if (isset($index[$needle])) {
            return $needle;
        }

        $best = null;
        $bestScore = 0.0;

        foreach (array_keys($index) as $candidate) {
            if ($candidate === '') continue;
            $score = 0.0;
            $score = max($score, similar_text($needle, $candidate) / max(1, max(strlen($needle), strlen($candidate))));
            $score = max($score, 1 - (levenshtein($needle, $candidate) / max(1, max(strlen($needle), strlen($candidate)))));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        if ($bestScore >= 0.82 && $best) {
            return $best;
        }

        return $needle;
    }

    /**
     * @param array<string,mixed> $orderData
     * @return array<string,mixed>
     */
    protected function buildMarketingIdentityContext(array $orderData, ?string $storeKey = null, ?int $tenantId = null): array
    {
        $email = trim((string) ($orderData['email'] ?? $orderData['customer']['email'] ?? ''));
        $phone = trim((string) (
            $orderData['phone']
            ?? $orderData['shipping_address']['phone']
            ?? $orderData['billing_address']['phone']
            ?? $orderData['customer']['phone']
            ?? $orderData['customer']['default_address']['phone']
            ?? ''
        ));

        $fullName = trim((string) (
            $orderData['shipping_address']['name']
            ?? $orderData['billing_address']['name']
            ?? $orderData['customer']['default_address']['name']
            ?? ''
        ));

        $firstName = trim((string) ($orderData['customer']['first_name'] ?? ''));
        $lastName = trim((string) ($orderData['customer']['last_name'] ?? ''));
        $shopifyCustomerId = trim((string) ($orderData['customer']['id'] ?? ''));

        $channels = ['shopify'];
        if ($storeKey !== '') {
            $channels[] = strtolower($storeKey) === 'wholesale' ? 'wholesale' : 'online';
        }

        $tags = strtolower((string) ($orderData['tags'] ?? ''));
        if (str_contains($tags, 'market') || str_contains($tags, 'event')) {
            $channels[] = 'event';
        }

        $couponSignals = $this->extractCouponSignals($orderData);
        $redemptionCodes = collect($couponSignals)
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->filter(fn (string $value): bool => str_starts_with($value, 'CC-'))
            ->values()
            ->all();
        $referralCode = $this->extractReferralCode($orderData);

        $orderTotal = $orderData['current_total_price']
            ?? $orderData['total_price']
            ?? null;

        return [
            'tenant_id' => $tenantId,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'full_name' => $fullName !== '' ? $fullName : null,
            'first_name' => $firstName !== '' ? $firstName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'shopify_customer_id' => $shopifyCustomerId !== '' ? $shopifyCustomerId : null,
            'source_channels' => array_values(array_unique($channels)),
            'source_links' => $shopifyCustomerId !== '' ? [[
                'source_type' => 'shopify_customer',
                'source_id' => ($storeKey !== '' ? $storeKey . ':' : '') . $shopifyCustomerId,
                'source_meta' => [
                    'shopify_customer_id' => $shopifyCustomerId,
                    'shopify_store_key' => $storeKey !== '' ? $storeKey : null,
                ],
            ]] : [],
            'coupon_signals' => $couponSignals,
            'applied_reward_codes' => $redemptionCodes,
            'referral_code' => $referralCode,
            'attribution_meta' => $this->attributionSourceMetaBuilder->fromShopifyOrderPayload($orderData, $storeKey),
            'order_total' => $orderTotal !== null && $orderTotal !== '' ? number_format((float) $orderTotal, 2, '.', '') : null,
        ];
    }

    protected function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * @param array<string,mixed> $orderData
     * @return array<int,string>
     */
    protected function extractCouponSignals(array $orderData): array
    {
        $signals = [];

        foreach ((array) ($orderData['discount_codes'] ?? []) as $row) {
            if (is_array($row)) {
                $code = trim((string) ($row['code'] ?? $row['discount_code'] ?? ''));
            } else {
                $code = trim((string) $row);
            }

            if ($code !== '') {
                $signals[] = $code;
            }
        }

        foreach ((array) ($orderData['discount_applications'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach (['code', 'title', 'description'] as $key) {
                $value = trim((string) ($row[$key] ?? ''));
                if ($value !== '') {
                    $signals[] = $value;
                }
            }
        }

        foreach ((array) ($orderData['note_attributes'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = strtolower(trim((string) ($row['name'] ?? '')));
            if (
                $name !== ''
                && ! str_contains($name, 'code')
                && ! str_contains($name, 'coupon')
                && ! str_contains($name, 'promo')
                && ! str_contains($name, 'reward')
            ) {
                continue;
            }

            $value = trim((string) ($row['value'] ?? ''));
            if ($value !== '') {
                $signals[] = $value;
            }
        }

        $raw = json_encode($orderData);
        if (is_string($raw) && $raw !== '') {
            preg_match_all('/\bCC-[A-Z0-9]{6,20}\b/i', strtoupper($raw), $matches);
            foreach ((array) ($matches[0] ?? []) as $code) {
                $signals[] = (string) $code;
            }
        }

        return collect($signals)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function extractReferralCode(array $orderData): ?string
    {
        $signals = [];

        foreach ((array) ($orderData['note_attributes'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = strtolower(trim((string) ($row['name'] ?? '')));
            if (
                $name !== ''
                && ! str_contains($name, 'ref')
                && ! str_contains($name, 'friend')
                && ! str_contains($name, 'share')
            ) {
                continue;
            }

            $value = trim((string) ($row['value'] ?? ''));
            if ($value !== '') {
                $signals[] = $value;
            }
        }

        $raw = json_encode($orderData);
        if (is_string($raw) && $raw !== '') {
            preg_match_all('/\bFOREST-[A-Z0-9]{3,32}\b/i', strtoupper($raw), $matches);
            foreach ((array) ($matches[0] ?? []) as $code) {
                $signals[] = (string) $code;
            }
        }

        return collect($signals)
            ->map(fn ($value): string => strtoupper(trim((string) $value)))
            ->filter(fn (string $value): bool => str_starts_with($value, 'FOREST-'))
            ->unique()
            ->first();
    }

    /**
     * @param  array<string,mixed>  $orderData
     * @return array<string,mixed>
     */
    protected function extractOrderFinancials(array $orderData): array
    {
        return [
            'currency_code' => $this->nullableString(
                $orderData['presentment_currency']
                    ?? $orderData['currency']
                    ?? data_get($orderData, 'current_total_price_set.shop_money.currency_code')
                    ?? data_get($orderData, 'total_price_set.shop_money.currency_code')
            ),
            'subtotal_price' => $this->moneyValue(
                $orderData['current_subtotal_price'] ?? null,
                $orderData['subtotal_price'] ?? null,
                data_get($orderData, 'current_subtotal_price_set.shop_money.amount'),
                data_get($orderData, 'subtotal_price_set.shop_money.amount')
            ),
            'discount_total' => $this->moneyValue(
                $orderData['current_total_discounts'] ?? null,
                $orderData['total_discounts'] ?? null,
                data_get($orderData, 'current_total_discounts_set.shop_money.amount'),
                data_get($orderData, 'total_discounts_set.shop_money.amount')
            ) ?? 0.0,
            'tax_total' => $this->moneyValue(
                $orderData['current_total_tax'] ?? null,
                $orderData['total_tax'] ?? null,
                data_get($orderData, 'current_total_tax_set.shop_money.amount'),
                data_get($orderData, 'total_tax_set.shop_money.amount')
            ) ?? 0.0,
            'shipping_total' => $this->moneyValue(
                data_get($orderData, 'current_total_shipping_price_set.shop_money.amount'),
                data_get($orderData, 'total_shipping_price_set.shop_money.amount'),
                $this->sumShippingLines((array) ($orderData['shipping_lines'] ?? []))
            ) ?? 0.0,
            'refund_total' => $this->extractRefundTotal($orderData),
            'total_price' => $this->moneyValue(
                $orderData['current_total_price'] ?? null,
                $orderData['total_price'] ?? null,
                data_get($orderData, 'current_total_price_set.shop_money.amount'),
                data_get($orderData, 'total_price_set.shop_money.amount')
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $line
     * @return array<string,mixed>
     */
    protected function extractLineFinancials(array $line): array
    {
        $quantity = max(1, (int) ($line['quantity'] ?? 1));
        $lineSubtotal = $this->moneyValue(
            $line['original_line_price'] ?? null,
            data_get($line, 'original_line_price_set.shop_money.amount'),
            (is_numeric($line['price'] ?? null) ? ((float) $line['price'] * $quantity) : null)
        );
        $discountTotal = $this->moneyValue(
            $line['total_discount'] ?? null,
            data_get($line, 'total_discount_set.shop_money.amount')
        ) ?? 0.0;
        $lineTotal = $this->moneyValue(
            $line['final_line_price'] ?? null,
            $line['discounted_total'] ?? null,
            data_get($line, 'final_line_price_set.shop_money.amount'),
            $lineSubtotal !== null ? max(0, $lineSubtotal - $discountTotal) : null
        );

        return [
            'shopify_product_id' => isset($line['product_id']) && is_numeric($line['product_id']) ? (int) $line['product_id'] : null,
            'shopify_variant_id' => isset($line['variant_id']) && is_numeric($line['variant_id']) ? (int) $line['variant_id'] : null,
            'currency_code' => $this->nullableString(
                data_get($line, 'price_set.shop_money.currency_code')
                    ?? data_get($line, 'discounted_price_set.shop_money.currency_code')
                    ?? data_get($line, 'original_line_price_set.shop_money.currency_code')
            ),
            'unit_price' => $this->deriveUnitPrice(
                $lineTotal,
                $lineSubtotal,
                $quantity,
                $this->moneyValue(
                    $line['current_price'] ?? null,
                    $line['price'] ?? null,
                    data_get($line, 'price_set.shop_money.amount'),
                    data_get($line, 'discounted_price_set.shop_money.amount')
                )
            ),
            'line_subtotal' => $lineSubtotal,
            'discount_total' => $discountTotal,
            'line_total' => $lineTotal,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $shippingLines
     */
    protected function sumShippingLines(array $shippingLines): ?float
    {
        $total = 0.0;
        $found = false;

        foreach ($shippingLines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $value = $this->moneyValue(
                $line['discounted_price'] ?? null,
                $line['price'] ?? null,
                data_get($line, 'discounted_price_set.shop_money.amount'),
                data_get($line, 'price_set.shop_money.amount')
            );

            if ($value !== null) {
                $total += $value;
                $found = true;
            }
        }

        return $found ? round($total, 2) : null;
    }

    /**
     * @param  array<string,mixed>  $orderData
     */
    protected function extractRefundTotal(array $orderData): float
    {
        $total = 0.0;

        foreach ((array) ($orderData['refunds'] ?? []) as $refund) {
            if (! is_array($refund)) {
                continue;
            }

            $value = $this->moneyValue(
                data_get($refund, 'total_refunded_set.shop_money.amount'),
                $refund['subtotal'] ?? null,
                data_get($refund, 'order_adjustments.0.amount_set.shop_money.amount'),
                data_get($refund, 'transactions.0.amount')
            );

            if ($value !== null) {
                $total += $value;
            }
        }

        return round($total, 2);
    }

    protected function deriveUnitPrice(?float $lineTotal, ?float $lineSubtotal, int $quantity, ?float $preferred = null): ?float
    {
        if ($preferred !== null && $preferred > 0) {
            return round($preferred, 2);
        }

        if ($quantity <= 0) {
            return null;
        }

        $basis = $lineTotal ?? $lineSubtotal;

        return $basis !== null ? round($basis / $quantity, 2) : null;
    }

    protected function sumMoney(?float $left, ?float $right): ?float
    {
        if ($left === null && $right === null) {
            return null;
        }

        return round((float) ($left ?? 0) + (float) ($right ?? 0), 2);
    }

    protected function moneyValue(mixed ...$values): ?float
    {
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_numeric($value)) {
                return round((float) $value, 2);
            }
        }

        return null;
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}

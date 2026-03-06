<?php

namespace App\Services\Shopify;

use App\Models\MappingException;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\ShopifyImportException;
use App\Models\WholesaleCustomScent;
use App\Services\Shipping\BusinessDayCalculator;
use App\Support\Shopify\InfiniteOptionsParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ShopifyOrderIngestor
{
    public function __construct(protected BusinessDayCalculator $calculator)
    {
    }

    /**
     * @param array{key: string, source: string} $store
     * @param array<string, mixed> $orderData
     * @return array{lines_count: int, merged_lines_count: int, mapping_exceptions_count: int}
     */
    public function ingest(array $store, array $orderData): array
    {
        $summary = [
            'lines_count' => 0,
            'merged_lines_count' => 0,
            'mapping_exceptions_count' => 0,
        ];

        $noteText = $this->buildOrderNote($orderData);
        $mergedLines = $this->mergeLineItems($orderData['line_items'] ?? [], $noteText, $orderData, $store['key'] ?? null);
        $summary['merged_lines_count'] = max(0, count($orderData['line_items'] ?? []) - count($mergedLines));

        $shopifyOrderId = isset($orderData['id']) ? (int) $orderData['id'] : null;
        if (!$shopifyOrderId) {
            return $summary;
        }

        DB::transaction(function () use ($store, $orderData, $mergedLines, $shopifyOrderId, &$summary): void {
            $order = Order::query()
                ->where('shopify_store_key', $store['key'])
                ->where('shopify_order_id', $shopifyOrderId)
                ->first() ?? new Order();

            $order->shopify_store_key = $store['key'];
            $order->shopify_order_id = $shopifyOrderId;
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

            if (empty($order->status)) {
                $order->status = 'new';
            }

            $order->save();

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

        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     * @param array<string, mixed> $orderData
     * @return array<int, array{sku: ?string, title: ?string, variant: ?string, quantity: int, line_item_id: ?int, wick_type: string, image_url: ?string, raw_title: ?string, raw_variant: ?string, scent_id: ?int, size_id?: ?int, external_key?: ?string, payload: array<string, mixed>}>
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

            if (!isset($merged[$key])) {
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
     * @param array{sku: ?string, title: ?string, variant: ?string, quantity: int, line_item_id: ?int, wick_type?: string, image_url?: ?string, raw_title?: ?string, raw_variant?: ?string, scent_id?: ?int, size_id?: ?int, external_key?: ?string} $line
     */
    protected function upsertLine(int $orderId, array $line): OrderLine
    {
        $updateAttributes = [
            'ordered_qty' => $line['quantity'],
            'quantity' => $line['quantity'],
            'sku' => $line['sku'],
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
            'external_key' => $line['external_key'] ?? null,
            'ordered_qty' => $line['quantity'],
            'extra_qty' => 0,
            'quantity' => $line['quantity'],
            'sku' => $line['sku'],
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

        return str_contains($value, 'sale candle');
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
}

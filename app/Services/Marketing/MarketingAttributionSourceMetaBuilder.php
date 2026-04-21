<?php

namespace App\Services\Marketing;

use App\Models\Order;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class MarketingAttributionSourceMetaBuilder
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
        'fbclid',
        'fbc',
        'fbp',
        'referrer',
        'referring_site',
        'landing_site',
        'landing_page',
        'source_url',
        'source_name',
        'source_type',
        'source_identifier',
        'checkout_token',
        'cart_token',
        'session_key',
        'session_id',
        'client_id',
        'email_module_type',
        'email_module_position',
        'email_product_id',
        'email_tile_position',
        'email_template_key',
        'email_source_label',
        'email_link_label',
    ];

    /**
     * @param  array<string,mixed>  $orderData
     * @return array<string,mixed>
     */
    public function fromShopifyOrderPayload(array $orderData, ?string $storeKey = null): array
    {
        $candidate = [];
        $fieldConfidence = [];

        $this->setField($candidate, $fieldConfidence, 'landing_site', $orderData['landing_site'] ?? null, 'high');
        $this->setField($candidate, $fieldConfidence, 'referrer', $orderData['referrer'] ?? $orderData['referer'] ?? null, 'high');
        $this->setField($candidate, $fieldConfidence, 'referring_site', $orderData['referring_site'] ?? null, 'high');
        $this->setField($candidate, $fieldConfidence, 'source_name', $orderData['source_name'] ?? null, 'high');
        $this->setField($candidate, $fieldConfidence, 'source_identifier', $orderData['source_identifier'] ?? null, 'high');
        $this->setField($candidate, $fieldConfidence, 'source_url', $orderData['source_url'] ?? null, 'high');
        $this->setField($candidate, $fieldConfidence, 'landing_page', $orderData['landing_page'] ?? null, 'high');
        $this->setField($candidate, $fieldConfidence, 'checkout_token', $orderData['checkout_token'] ?? null, 'high');
        $this->setField($candidate, $fieldConfidence, 'cart_token', $orderData['cart_token'] ?? null, 'high');
        $this->setField($candidate, $fieldConfidence, 'fbclid', $orderData['fbclid'] ?? null, 'high');
        $this->setField($candidate, $fieldConfidence, 'fbc', $orderData['fbc'] ?? null, 'high');
        $this->setField($candidate, $fieldConfidence, 'fbp', $orderData['fbp'] ?? null, 'high');
        $this->setField($candidate, $fieldConfidence, 'source_type', 'shopify_order_payload', 'medium');

        foreach ($this->extractClientSignals((array) ($orderData['client_details'] ?? []), $orderData) as $key => $value) {
            $this->setField($candidate, $fieldConfidence, $key, $value, 'medium');
        }

        $orderTags = $this->normalizeTags($orderData['tags'] ?? null);
        if ($orderTags !== []) {
            $candidate['order_tags'] = $orderTags;
        }

        $noteSignals = $this->extractNoteAttributeSignals((array) ($orderData['note_attributes'] ?? []));
        foreach ($noteSignals as $key => $value) {
            $this->setField($candidate, $fieldConfidence, $key, $value, 'medium');
        }
        if ($noteSignals !== []) {
            $candidate['note_attribute_signals'] = $noteSignals;
        }

        foreach ([$candidate['landing_site'] ?? null, $candidate['landing_page'] ?? null, $candidate['source_url'] ?? null] as $url) {
            foreach ($this->extractAttributionQuerySignals($url) as $key => $value) {
                $this->setField($candidate, $fieldConfidence, $key, $value, 'high');
            }
            foreach ($this->extractEmailQuerySignals($url) as $key => $value) {
                $this->setField($candidate, $fieldConfidence, $key, $value, 'medium');
            }
        }

        if (! empty($candidate['referring_site']) && empty($candidate['referrer'])) {
            $this->setField($candidate, $fieldConfidence, 'referrer', $candidate['referring_site'], 'high');
        }

        if ($storeKey) {
            $candidate['shopify_store_key'] = $storeKey;
        }

        $meta = $this->finalize($candidate, $fieldConfidence, 'shopify_order_payload');
        if ($meta === []) {
            return [];
        }

        $meta['captured_at'] = now()->toIso8601String();
        $meta['ingested_attribution_version'] = 1;

        return $meta;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    public function fromMeta(array $meta, ?string $captureContext = null): array
    {
        $meta = $this->augmentMetaFromKnownUrls($meta);
        $candidate = [];
        $fieldConfidence = is_array($meta['field_confidence'] ?? null) ? $meta['field_confidence'] : [];
        $defaultConfidence = $this->normalizeConfidence($meta['confidence'] ?? null) ?? 'medium';

        foreach ($this->fields as $field) {
            if (array_key_exists($field, $meta)) {
                $this->setField(
                    $candidate,
                    $fieldConfidence,
                    $field,
                    $meta[$field],
                    $this->normalizeConfidence($fieldConfidence[$field] ?? null) ?? $defaultConfidence
                );
            }
        }

        return $this->finalize($candidate, $fieldConfidence, $captureContext ?: $this->nullableString($meta['capture_context'] ?? null) ?: 'source_meta');
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function fromContext(array $context): array
    {
        $meta = is_array($context['attribution_meta'] ?? null) ? $context['attribution_meta'] : [];

        return $this->fromMeta($meta, 'identity_context');
    }

    /**
     * @param  array<string,mixed>  $existing
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function mergeSourceMeta(array $existing, array $candidate): array
    {
        $existing = $this->augmentMetaFromKnownUrls($existing);
        $candidate = $this->augmentMetaFromKnownUrls($candidate);
        $merged = $existing;
        $existingFieldConfidence = is_array($merged['field_confidence'] ?? null) ? $merged['field_confidence'] : [];
        $candidateFieldConfidence = is_array($candidate['field_confidence'] ?? null) ? $candidate['field_confidence'] : [];
        $changed = false;

        foreach ($candidate as $key => $value) {
            if (in_array($key, array_merge($this->fields, ['capture_context', 'capture_contexts', 'field_confidence', 'confidence', 'last_enriched_at']), true)) {
                continue;
            }

            if (! array_key_exists($key, $merged) || $merged[$key] === null || $merged[$key] === '') {
                $merged[$key] = $value;
                $changed = true;
            }
        }

        foreach ($this->fields as $field) {
            $candidateValue = $candidate[$field] ?? null;
            if ($candidateValue === null || $candidateValue === '') {
                continue;
            }

            $existingValue = $merged[$field] ?? null;
            $existingRank = $this->confidenceRank($existingFieldConfidence[$field] ?? ($merged['confidence'] ?? null));
            $candidateRank = $this->confidenceRank($candidateFieldConfidence[$field] ?? ($candidate['confidence'] ?? null));

            if ($existingValue === null || $existingValue === '' || $candidateRank > $existingRank) {
                $merged[$field] = $candidateValue;
                $existingFieldConfidence[$field] = $candidateFieldConfidence[$field] ?? $candidate['confidence'] ?? 'medium';
                $changed = true;
            }
        }

        $contexts = collect(array_merge(
            Arr::wrap($merged['capture_contexts'] ?? []),
            Arr::wrap($candidate['capture_contexts'] ?? []),
            Arr::wrap($merged['capture_context'] ?? []),
            Arr::wrap($candidate['capture_context'] ?? [])
        ))
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->unique()
            ->values()
            ->all();

        if ($contexts !== []) {
            if (($merged['capture_contexts'] ?? []) !== $contexts || ($merged['capture_context'] ?? null) !== $contexts[0]) {
                $changed = true;
            }
            $merged['capture_contexts'] = $contexts;
            $merged['capture_context'] = $contexts[0];
        }

        $merged['field_confidence'] = $existingFieldConfidence;
        $merged['confidence'] = $this->maxConfidence($existingFieldConfidence);

        if (! $changed) {
            return $existing;
        }

        $merged['last_enriched_at'] = now()->toIso8601String();

        return $merged;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function sourceMetaForOrderLink(Order $order, array $context = []): array
    {
        $candidate = $this->mergeSourceMeta(
            $this->storedOrderAttributionMeta($order),
            $this->fromContext($context)
        );

        if (! array_key_exists('source_type', $candidate)) {
            $candidate['source_type'] = 'shopify_order_link';
        }

        return $candidate;
    }

    /**
     * @return array<string,mixed>
     */
    public function storedOrderAttributionMeta(Order $order): array
    {
        return is_array($order->attribution_meta ?? null) ? $order->attribution_meta : [];
    }

    /**
     * @param  array<int,array<string,mixed>>  $noteAttributes
     * @return array<string,string>
     */
    protected function extractNoteAttributeSignals(array $noteAttributes): array
    {
        $signals = [];

        foreach ($noteAttributes as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = strtolower(trim((string) ($row['name'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }

            $normalizedName = str_replace(['-', ' '], '_', $name);

            $field = match (true) {
                str_contains($normalizedName, 'utm_source') => 'utm_source',
                str_contains($normalizedName, 'utm_medium') => 'utm_medium',
                str_contains($normalizedName, 'utm_campaign') => 'utm_campaign',
                str_contains($normalizedName, 'utm_content') => 'utm_content',
                str_contains($normalizedName, 'utm_term') => 'utm_term',
                in_array($normalizedName, ['fbclid', 'fbc', 'fbp'], true) => $normalizedName,
                in_array($normalizedName, ['referrer', 'referer'], true) => 'referrer',
                $normalizedName === 'referring_site' => 'referring_site',
                str_contains($normalizedName, 'landing_site') => 'landing_site',
                str_contains($normalizedName, 'landing_page') => 'landing_page',
                str_contains($normalizedName, 'source_url') => 'source_url',
                str_contains($normalizedName, 'source_name') => 'source_name',
                str_contains($normalizedName, 'source_type') => 'source_type',
                str_contains($normalizedName, 'source_identifier') => 'source_identifier',
                str_contains($normalizedName, 'checkout_token') => 'checkout_token',
                str_contains($normalizedName, 'cart_token') => 'cart_token',
                str_contains($normalizedName, 'session_key') => 'session_key',
                $normalizedName === 'session_id' => 'session_id',
                str_contains($normalizedName, 'client_id') => 'client_id',
                str_contains($normalizedName, 'email_module_type') || str_contains($normalizedName, 'mf_module_type') => 'email_module_type',
                str_contains($normalizedName, 'email_module_position') || str_contains($normalizedName, 'mf_module_position') => 'email_module_position',
                str_contains($normalizedName, 'email_product_id') || str_contains($normalizedName, 'mf_product_id') => 'email_product_id',
                str_contains($normalizedName, 'email_tile_position') || str_contains($normalizedName, 'mf_tile_position') => 'email_tile_position',
                str_contains($normalizedName, 'email_template_key') || str_contains($normalizedName, 'mf_template_key') => 'email_template_key',
                str_contains($normalizedName, 'email_source_label') || str_contains($normalizedName, 'mf_source_label') => 'email_source_label',
                str_contains($normalizedName, 'email_link_label') || str_contains($normalizedName, 'mf_link_label') => 'email_link_label',
                default => null,
            };

            if ($field) {
                $signals[$field] = $value;
            }
        }

        return $signals;
    }

    /**
     * @return array<string,string>
     */
    protected function extractAttributionQuerySignals(mixed $url): array
    {
        $url = $this->nullableString($url);
        if (! $url) {
            return [];
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['query'])) {
            return [];
        }

        parse_str((string) $parts['query'], $query);

        $signals = [];
        foreach ([
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
            'fbclid',
            'fbc',
            'fbp',
            'checkout_token',
            'cart_token',
            'session_key',
            'session_id',
            'client_id',
        ] as $field) {
            $value = $this->nullableString($query[$field] ?? null);
            if ($value !== null) {
                $signals[$field] = $value;
            }
        }

        return $signals;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    protected function augmentMetaFromKnownUrls(array $meta): array
    {
        if ($meta === []) {
            return $meta;
        }

        $enriched = $meta;
        $fieldConfidence = is_array($enriched['field_confidence'] ?? null) ? $enriched['field_confidence'] : [];
        $defaultConfidence = $this->normalizeConfidence($enriched['confidence'] ?? null) ?? 'medium';
        $changed = false;

        $urls = [
            $this->nullableString($enriched['landing_site'] ?? null),
            $this->nullableString($enriched['landing_page'] ?? null),
            $this->nullableString($enriched['source_url'] ?? null),
        ];

        foreach ($urls as $url) {
            if ($url === null) {
                continue;
            }

            foreach ($this->extractAttributionQuerySignals($url) as $field => $value) {
                if (! in_array($field, $this->fields, true)) {
                    continue;
                }

                if ($this->nullableString($enriched[$field] ?? null) !== null) {
                    continue;
                }

                $enriched[$field] = $value;
                $fieldConfidence[$field] = in_array($field, ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'fbc', 'fbp'], true)
                    ? 'high'
                    : $defaultConfidence;
                $changed = true;
            }

            foreach ($this->extractEmailQuerySignals($url) as $field => $value) {
                if (! in_array($field, $this->fields, true)) {
                    continue;
                }

                if ($this->nullableString($enriched[$field] ?? null) !== null) {
                    continue;
                }

                $enriched[$field] = $value;
                $fieldConfidence[$field] = $defaultConfidence;
                $changed = true;
            }
        }

        $sessionKey = $this->nullableString($enriched['session_key'] ?? null);
        $sessionId = $this->nullableString($enriched['session_id'] ?? null);
        if ($sessionKey === null && $sessionId !== null) {
            $enriched['session_key'] = $sessionId;
            $fieldConfidence['session_key'] = $fieldConfidence['session_id'] ?? $defaultConfidence;
            $changed = true;
        } elseif ($sessionId === null && $sessionKey !== null) {
            $enriched['session_id'] = $sessionKey;
            $fieldConfidence['session_id'] = $fieldConfidence['session_key'] ?? $defaultConfidence;
            $changed = true;
        }

        if (! $changed) {
            return $meta;
        }

        $enriched['field_confidence'] = $fieldConfidence;
        $enriched['confidence'] = $this->maxConfidence($fieldConfidence);
        $enriched['last_enriched_at'] = now()->toIso8601String();

        return $enriched;
    }

    /**
     * @return array<string,string>
     */
    protected function extractEmailQuerySignals(mixed $url): array
    {
        $url = $this->nullableString($url);
        if (! $url) {
            return [];
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['query'])) {
            return [];
        }

        parse_str((string) $parts['query'], $query);
        if (! is_array($query)) {
            return [];
        }

        $map = [
            'mf_module_type' => 'email_module_type',
            'mf_module_position' => 'email_module_position',
            'mf_product_id' => 'email_product_id',
            'mf_tile_position' => 'email_tile_position',
            'mf_template_key' => 'email_template_key',
            'mf_source_label' => 'email_source_label',
            'mf_link_label' => 'email_link_label',
            'mf_delivery_id' => 'source_identifier',
        ];

        $signals = [];
        foreach ($map as $queryKey => $metaKey) {
            $value = $this->nullableString($query[$queryKey] ?? null);
            if ($value !== null) {
                $signals[$metaKey] = $value;
            }
        }

        return $signals;
    }

    /**
     * @param  array<string,mixed>  $clientDetails
     * @param  array<string,mixed>  $orderData
     * @return array<string,mixed>
     */
    protected function extractClientSignals(array $clientDetails, array $orderData): array
    {
        $signals = [];

        foreach ([
            'browser_ip' => $orderData['browser_ip'] ?? $clientDetails['browser_ip'] ?? null,
            'user_agent' => $clientDetails['user_agent'] ?? null,
            'accept_language' => $clientDetails['accept_language'] ?? null,
            'session_hash' => $clientDetails['session_hash'] ?? null,
            'session_id' => $clientDetails['session_hash'] ?? $orderData['session_id'] ?? null,
            'client_id' => $orderData['client_id'] ?? null,
        ] as $field => $value) {
            $value = $this->nullableString($value);
            if ($value !== null) {
                $signals[$field] = $value;
            }
        }

        return $signals;
    }

    /**
     * @return array<int,string>
     */
    protected function normalizeTags(mixed $tags): array
    {
        if (is_array($tags)) {
            $values = $tags;
        } else {
            $values = preg_split('/[,|]/', (string) $tags) ?: [];
        }

        return collect($values)
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,string>  $fieldConfidence
     * @return array<string,mixed>
     */
    protected function finalize(array $candidate, array $fieldConfidence, string $captureContext): array
    {
        $candidate = array_filter($candidate, fn ($value) => $value !== null && $value !== '');
        if ($candidate === []) {
            return [];
        }

        $candidate['capture_context'] = $captureContext;
        $candidate['capture_contexts'] = [$captureContext];
        $candidate['field_confidence'] = $fieldConfidence;
        $candidate['confidence'] = $this->maxConfidence($fieldConfidence);
        $candidate['last_enriched_at'] = now()->toIso8601String();

        return $candidate;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,string>  $fieldConfidence
     */
    protected function setField(array &$candidate, array &$fieldConfidence, string $field, mixed $value, string $confidence): void
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return;
        }

        $candidate[$field] = $value;
        $fieldConfidence[$field] = $confidence;
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function normalizeConfidence(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['high', 'medium', 'low'], true) ? $value : null;
    }

    protected function confidenceRank(mixed $confidence): int
    {
        return match ($this->normalizeConfidence($confidence)) {
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }

    /**
     * @param  array<string,string>  $fieldConfidence
     */
    protected function maxConfidence(array $fieldConfidence): string
    {
        $max = collect($fieldConfidence)
            ->map(fn ($value) => $this->confidenceRank($value))
            ->max();

        return match ($max) {
            3 => 'high',
            2 => 'medium',
            default => 'low',
        };
    }
}

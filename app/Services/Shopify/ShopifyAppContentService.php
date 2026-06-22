<?php

namespace App\Services\Shopify;

use App\Models\TenantMarketingSetting;
use Illuminate\Support\Facades\Schema;

class ShopifyAppContentService
{
    public const SETTING_KEY = 'modern_forestry_shopify_app_content';

    /**
     * @return array<string,mixed>
     */
    public function defaults(): array
    {
        return [
            'brand_name' => 'Modern Forestry',
            'hero_eyebrow' => 'Customer account',
            'hero_title' => 'Your Modern Forestry account',
            'hero_body' => 'Check rewards, recent orders, and quick actions in one place.',
            'primary_cta_label' => 'View rewards',
            'secondary_cta_label' => 'Review orders',
            'rewards_title' => 'Rewards',
            'rewards_body' => 'Redeem on Shopify checkout when you are ready.',
            'orders_title' => 'Recent orders',
            'orders_body' => 'Reorder the items you want again with a Shopify cart handoff.',
            'support_title' => 'Support',
            'support_body' => 'Need help? Reach out and we will follow up.',
            'support_cta_label' => 'Contact support',
            'support_email' => 'support@modernforestry.com',
            'support_url' => null,
            'privacy_url' => 'https://modernforestry.com/policies/privacy-policy',
            'terms_url' => 'https://modernforestry.com/policies/terms-of-service',
            'data_deletion_url' => null,
            'data_deletion_email' => 'support@modernforestry.com',
            'empty_rewards' => 'No active rewards right now.',
            'empty_orders' => 'No recent orders yet.',
            'account_note' => 'For privacy or account data requests, contact Modern Forestry support.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function forTenant(int $tenantId): array
    {
        $defaults = $this->defaults();
        $record = $this->storedSetting($tenantId);
        $storedValue = is_array($record?->value) ? $record->value : [];
        $draft = $this->normalizeSnapshot(is_array($storedValue['draft'] ?? null) ? $storedValue['draft'] : $storedValue, $defaults);
        $published = is_array($storedValue['published'] ?? null)
            ? $this->normalizeSnapshot($storedValue['published'], $defaults)
            : null;

        return [
            'setting_key' => self::SETTING_KEY,
            'exists' => $record !== null,
            'draft' => $draft,
            'published' => $published,
            'effective' => $published ?? $defaults,
            'defaults' => $defaults,
            'draft_updated_at' => data_get($storedValue, 'draft_updated_at')
                ?? optional($record?->updated_at)->toIso8601String(),
            'published_at' => data_get($storedValue, 'published_at'),
            'updated_by' => data_get($storedValue, 'updated_by'),
            'published_by' => data_get($storedValue, 'published_by'),
            'description' => (string) ($record?->description ?? 'Modern Forestry customer dashboard copy.'),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function saveDraft(int $tenantId, array $payload, ?array $meta = null): TenantMarketingSetting
    {
        return $this->persistSnapshot($tenantId, $payload, false, $meta);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function publish(int $tenantId, array $payload, ?array $meta = null): TenantMarketingSetting
    {
        return $this->persistSnapshot($tenantId, $payload, true, $meta);
    }

    public function ensureDefaults(int $tenantId): void
    {
        if (! Schema::hasTable('tenant_marketing_settings')) {
            return;
        }

        $existing = $this->storedSetting($tenantId);
        if ($existing) {
            return;
        }

        TenantMarketingSetting::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'key' => self::SETTING_KEY,
            ],
            [
                'value' => [
                    'draft' => $this->defaults(),
                    'published' => $this->defaults(),
                    'draft_updated_at' => now()->toIso8601String(),
                    'published_at' => now()->toIso8601String(),
                    'updated_by' => 'bootstrap',
                    'published_by' => 'bootstrap',
                ],
                'description' => 'Modern Forestry customer dashboard copy.',
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>|null  $meta
     */
    protected function persistSnapshot(int $tenantId, array $payload, bool $publish, ?array $meta = null): TenantMarketingSetting
    {
        if (! Schema::hasTable('tenant_marketing_settings')) {
            throw new \RuntimeException('tenant_marketing_settings table is required for Shopify app content.');
        }

        $existing = $this->storedSetting($tenantId);
        $storedValue = is_array($existing?->value) ? $existing->value : [];
        $defaults = $this->defaults();
        $currentPublished = is_array($storedValue['published'] ?? null)
            ? $this->normalizeSnapshot($storedValue['published'], $defaults)
            : null;
        $snapshot = $this->normalizeSnapshot($payload, $currentPublished ?? $defaults);
        $timestamp = now()->toIso8601String();

        $nextValue = [
            'draft' => $snapshot,
            'published' => $publish ? $snapshot : $currentPublished,
            'draft_updated_at' => $timestamp,
            'published_at' => $publish ? $timestamp : data_get($storedValue, 'published_at'),
            'updated_by' => $this->metaValue($meta, 'updated_by') ?? data_get($storedValue, 'updated_by'),
            'published_by' => $publish
                ? ($this->metaValue($meta, 'published_by') ?? $this->metaValue($meta, 'updated_by') ?? data_get($storedValue, 'published_by'))
                : data_get($storedValue, 'published_by'),
        ];

        return TenantMarketingSetting::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'key' => self::SETTING_KEY,
            ],
            [
                'value' => $nextValue,
                'description' => 'Modern Forestry customer dashboard copy.',
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    protected function metaValue(?array $meta, string $key): ?string
    {
        if (! is_array($meta)) {
            return null;
        }

        $value = trim((string) ($meta[$key] ?? ''));

        return $value !== '' ? $value : null;
    }

    protected function storedSetting(int $tenantId): ?TenantMarketingSetting
    {
        if (! Schema::hasTable('tenant_marketing_settings')) {
            return null;
        }

        return TenantMarketingSetting::query()
            ->where('tenant_id', $tenantId)
            ->where('key', self::SETTING_KEY)
            ->first();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $fallback
     * @return array<string,mixed>
     */
    protected function normalizeSnapshot(array $payload, array $fallback): array
    {
        $defaults = $this->defaults();
        $source = array_merge($defaults, $fallback, $payload);

        return [
            'brand_name' => $this->normalizedText($source['brand_name'] ?? null, $defaults['brand_name']),
            'hero_eyebrow' => $this->normalizedText($source['hero_eyebrow'] ?? null, $defaults['hero_eyebrow']),
            'hero_title' => $this->normalizedText($source['hero_title'] ?? null, $defaults['hero_title']),
            'hero_body' => $this->normalizedText($source['hero_body'] ?? null, $defaults['hero_body']),
            'primary_cta_label' => $this->normalizedText($source['primary_cta_label'] ?? null, $defaults['primary_cta_label']),
            'secondary_cta_label' => $this->normalizedText($source['secondary_cta_label'] ?? null, $defaults['secondary_cta_label']),
            'rewards_title' => $this->normalizedText($source['rewards_title'] ?? null, $defaults['rewards_title']),
            'rewards_body' => $this->normalizedText($source['rewards_body'] ?? null, $defaults['rewards_body']),
            'orders_title' => $this->normalizedText($source['orders_title'] ?? null, $defaults['orders_title']),
            'orders_body' => $this->normalizedText($source['orders_body'] ?? null, $defaults['orders_body']),
            'support_title' => $this->normalizedText($source['support_title'] ?? null, $defaults['support_title']),
            'support_body' => $this->normalizedText($source['support_body'] ?? null, $defaults['support_body']),
            'support_cta_label' => $this->normalizedText($source['support_cta_label'] ?? null, $defaults['support_cta_label']),
            'support_email' => $this->normalizedEmail($source['support_email'] ?? null, $defaults['support_email']),
            'support_url' => $this->normalizedUrl($source['support_url'] ?? null, $defaults['support_url']),
            'privacy_url' => $this->normalizedUrl($source['privacy_url'] ?? null, $defaults['privacy_url']),
            'terms_url' => $this->normalizedUrl($source['terms_url'] ?? null, $defaults['terms_url']),
            'data_deletion_url' => $this->normalizedUrl($source['data_deletion_url'] ?? null, $defaults['data_deletion_url']),
            'data_deletion_email' => $this->normalizedEmail($source['data_deletion_email'] ?? null, $defaults['data_deletion_email']),
            'empty_rewards' => $this->normalizedText($source['empty_rewards'] ?? null, $defaults['empty_rewards']),
            'empty_orders' => $this->normalizedText($source['empty_orders'] ?? null, $defaults['empty_orders']),
            'account_note' => $this->normalizedText($source['account_note'] ?? null, $defaults['account_note']),
        ];
    }

    protected function normalizedText(mixed $value, ?string $fallback = null): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return $fallback;
        }

        return $normalized;
    }

    protected function normalizedEmail(mixed $value, ?string $fallback = null): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return $fallback;
        }

        return filter_var($normalized, FILTER_VALIDATE_EMAIL) ? $normalized : $fallback;
    }

    protected function normalizedUrl(mixed $value, ?string $fallback = null): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return $fallback;
        }

        if (! filter_var($normalized, FILTER_VALIDATE_URL)) {
            return $fallback;
        }

        $scheme = strtolower((string) parse_url($normalized, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $normalized : $fallback;
    }
}

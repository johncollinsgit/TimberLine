<?php

namespace App\Services\Shopify;

use App\Models\TenantMarketingSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Stores preparation-only settings for orders Modern Forestry receives from a
 * third-party fundraiser. This is deliberately not an order, customer, tax,
 * Stripe, or Shopify mutation path.
 */
class ModernForestryFundraiserInvoiceSettingsService
{
    public const SETTING_KEY = 'modern_forestry_fundraiser_invoice_settings';

    /** @return array<string,mixed> */
    public function defaults(): array
    {
        return [
            'fundraiser_name' => null,
            'campaign_reference' => null,
            'invoice_payer_name' => null,
            'invoice_payer_email' => null,
            'notification_email' => 'info@theforestrystudio.com',
            'invoice_cadence' => 'per_order',
            'payment_terms_days' => 14,
            'shipping_treatment' => 'source_amount',
            'tax_handling' => 'manual_review_required',
        ];
    }

    /** @return array<string,mixed> */
    public function forTenant(int $tenantId): array
    {
        $record = $this->storedSetting($tenantId);
        $settings = $this->normalize(is_array($record?->value) ? $record->value : []);

        return [
            'setting_key' => self::SETTING_KEY,
            'exists' => $record !== null,
            'settings' => $settings,
            'configured' => filled($settings['fundraiser_name'])
                && filled($settings['invoice_payer_name'])
                && filled($settings['invoice_payer_email']),
            'zapier_webhook_configured' => filled(data_get($record?->value, 'zapier_webhook_secret_encrypted')),
            'updated_at' => optional($record?->updated_at)->toIso8601String(),
            'updated_by' => data_get($record?->value, 'updated_by'),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function saveForTenant(int $tenantId, array $payload, ?string $updatedBy = null): TenantMarketingSetting
    {
        if (! Schema::hasTable('tenant_marketing_settings')) {
            throw new \RuntimeException('tenant_marketing_settings table is required for fundraiser invoice settings.');
        }

        $settings = $this->normalize($payload);

        $existing = $this->storedSetting($tenantId);
        $existingValue = is_array($existing?->value) ? $existing->value : [];

        return TenantMarketingSetting::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'key' => self::SETTING_KEY],
            [
                'value' => [
                    ...$settings,
                    'zapier_webhook_secret_encrypted' => $existingValue['zapier_webhook_secret_encrypted'] ?? null,
                    'updated_by' => $updatedBy,
                    'updated_at' => now()->toIso8601String(),
                ],
                'description' => 'Modern Forestry third-party fundraiser Zapier intake and accounting-review package settings. It does not enable QuickBooks write-back, payment collection, tax calculation, invoice delivery, or recipient-open tracking.',
            ]
        );
    }

    /** @return array{secret:string,settings:array<string,mixed>} */
    public function rotateZapierWebhookSecret(int $tenantId, ?string $updatedBy = null): array
    {
        if (! Schema::hasTable('tenant_marketing_settings')) {
            throw new \RuntimeException('tenant_marketing_settings table is required for fundraiser invoice settings.');
        }

        $record = $this->storedSetting($tenantId);
        $value = is_array($record?->value) ? $record->value : [];
        $secret = Str::random(64);
        $value['zapier_webhook_secret_encrypted'] = Crypt::encryptString($secret);
        $value['zapier_webhook_secret_rotated_at'] = now()->toIso8601String();
        $value['updated_by'] = $updatedBy;
        $value['updated_at'] = now()->toIso8601String();

        TenantMarketingSetting::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'key' => self::SETTING_KEY],
            [
                'value' => $value,
                'description' => 'Modern Forestry third-party fundraiser Zapier intake and accounting-review package settings. It does not enable QuickBooks write-back, payment collection, tax calculation, invoice delivery, or recipient-open tracking.',
            ]
        );

        return ['secret' => $secret, 'settings' => $this->forTenant($tenantId)];
    }

    public function hasValidZapierWebhookSecret(int $tenantId, ?string $provided): bool
    {
        if (! filled($provided)) {
            return false;
        }

        $record = $this->storedSetting($tenantId);
        $encrypted = data_get($record?->value, 'zapier_webhook_secret_encrypted');
        if (! is_string($encrypted) || $encrypted === '') {
            return false;
        }

        try {
            return hash_equals(Crypt::decryptString($encrypted), (string) $provided);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    protected function normalize(array $payload): array
    {
        $defaults = $this->defaults();

        return [
            'fundraiser_name' => $this->text($payload['fundraiser_name'] ?? null),
            'campaign_reference' => $this->text($payload['campaign_reference'] ?? null),
            'invoice_payer_name' => $this->text($payload['invoice_payer_name'] ?? null),
            'invoice_payer_email' => $this->email($payload['invoice_payer_email'] ?? null),
            'notification_email' => $this->email($payload['notification_email'] ?? null) ?? $defaults['notification_email'],
            'invoice_cadence' => in_array($payload['invoice_cadence'] ?? null, ['per_order', 'weekly_summary', 'campaign_close'], true)
                ? $payload['invoice_cadence']
                : $defaults['invoice_cadence'],
            'payment_terms_days' => max(1, min(90, (int) ($payload['payment_terms_days'] ?? $defaults['payment_terms_days']))),
            'shipping_treatment' => in_array($payload['shipping_treatment'] ?? null, ['source_amount', 'manual_review'], true)
                ? $payload['shipping_treatment']
                : $defaults['shipping_treatment'],
            'tax_handling' => in_array($payload['tax_handling'] ?? null, ['manual_review_required', 'source_amount_pending_review'], true)
                ? $payload['tax_handling']
                : $defaults['tax_handling'],
        ];
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

    protected function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    protected function email(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }
}

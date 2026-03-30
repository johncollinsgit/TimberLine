<?php

namespace App\Services\Marketing;

use App\Models\MarketingStorefrontEvent;
use App\Models\MarketingProfile;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class MarketingStorefrontEventLogger
{
    /**
     * @param array<string,mixed> $context
     */
    public function log(string $eventType, array $context = []): MarketingStorefrontEvent
    {
        $eventType = trim($eventType);
        if ($eventType === '') {
            $eventType = 'storefront_event';
        }

        $profileId = $this->profileIdFromContext($context);
        $tenantId = $this->tenantIdFromContext($context);
        $meta = $this->sanitizeMeta((array) ($context['meta'] ?? []));

        $attributes = [
            'event_type' => $eventType,
            'status' => $this->normalizeStatus((string) ($context['status'] ?? 'ok')),
            'issue_type' => $this->nullableString($context['issue_type'] ?? null),
            'source_surface' => $this->nullableString($context['source_surface'] ?? null),
            'endpoint' => $this->nullableString($context['endpoint'] ?? null),
            'request_key' => $this->nullableString($context['request_key'] ?? null),
            'signature_mode' => $this->nullableString($context['signature_mode'] ?? null),
            'tenant_id' => $tenantId,
            'marketing_profile_id' => $profileId > 0 ? $profileId : null,
            'event_instance_id' => $this->positiveInt($context['event_instance_id'] ?? null),
            'candle_cash_redemption_id' => $this->positiveInt($context['candle_cash_redemption_id'] ?? null),
            'source_type' => $this->nullableString($context['source_type'] ?? null),
            'source_id' => $this->nullableString($context['source_id'] ?? null),
            'meta' => $meta !== [] ? $meta : null,
            'occurred_at' => $this->asDateTime($context['occurred_at'] ?? null) ?: now(),
            'resolution_status' => in_array((string) ($context['resolution_status'] ?? 'open'), ['open', 'resolved', 'ignored'], true)
                ? (string) ($context['resolution_status'] ?? 'open')
                : 'open',
        ];

        $dedupe = trim((string) ($context['dedupe_key'] ?? ''));
        if ($dedupe !== '') {
            $attributes['request_key'] = $attributes['request_key'] ?: $dedupe;

            $match = [
                'event_type' => $eventType,
                'request_key' => $dedupe,
            ];
            if ($tenantId !== null) {
                $match['tenant_id'] = $tenantId;
            }

            return MarketingStorefrontEvent::query()->updateOrCreate(
                $match,
                $attributes
            );
        }

        return MarketingStorefrontEvent::query()->create($attributes);
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    protected function sanitizeMeta(array $meta): array
    {
        $sanitized = [];
        foreach ($meta as $key => $value) {
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            if (in_array($normalizedKey, ['email', 'raw_email'], true)) {
                $sanitized[$normalizedKey] = $this->maskEmail((string) $value);
                continue;
            }
            if (in_array($normalizedKey, ['phone', 'raw_phone'], true)) {
                $sanitized[$normalizedKey] = $this->maskPhone((string) $value);
                continue;
            }

            if (is_array($value)) {
                $sanitized[$normalizedKey] = $this->sanitizeMeta($value);
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $sanitized[$normalizedKey] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * @param array<string,mixed> $context
     */
    protected function profileIdFromContext(array $context): int
    {
        $profile = $context['profile'] ?? null;
        if ($profile instanceof MarketingProfile) {
            return (int) $profile->id;
        }

        return (int) ($context['marketing_profile_id'] ?? 0);
    }

    /**
     * @param array<string,mixed> $context
     */
    protected function tenantIdFromContext(array $context): ?int
    {
        $profile = $context['profile'] ?? null;
        if ($profile instanceof MarketingProfile) {
            $tenantId = (int) ($profile->tenant_id ?? 0);
            if ($tenantId > 0) {
                return $tenantId;
            }
        }

        $tenantId = (int) ($context['tenant_id'] ?? 0);

        return $tenantId > 0 ? $tenantId : null;
    }

    protected function maskEmail(string $value): string
    {
        $value = trim(strtolower($value));
        if ($value === '' || ! str_contains($value, '@')) {
            return '';
        }

        [$local, $domain] = array_pad(explode('@', $value, 2), 2, '');
        if ($local === '' || $domain === '') {
            return '';
        }

        $visible = substr($local, 0, 1);

        return $visible . '***@' . $domain;
    }

    protected function maskPhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return '';
        }

        $suffix = substr($digits, -4);

        return '***' . $suffix;
    }

    protected function normalizeStatus(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['ok', 'error', 'pending', 'verification_required', 'resolved'], true)
            ? $value
            : 'ok';
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    protected function asDateTime(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}

<?php

namespace App\Services\Marketing;

use App\Models\MarketingEmailDelivery;

class MarketingEmailDeliveryProviderContext
{
    /**
     * @param  array<string,mixed>  $metadata
     * @return array{
     *   provider:string,
     *   provider_resolution_source:'tenant'|'fallback'|'none'|'unknown',
     *   provider_resolution_source_label:string,
     *   provider_readiness_status:'ready'|'unsupported'|'incomplete'|'error'|'not_configured'|'unknown',
     *   provider_readiness_status_label:string,
     *   provider_config_status:'configured'|'disabled'|'incomplete'|'error'|'not_configured'|'unknown',
     *   provider_using_fallback_config:bool,
     *   provider_runtime_path:string,
     *   provider_runtime_path_label:string,
     *   legacy_context_missing:bool,
     *   notes:array<int,string>
     * }
     */
    public function resolveFromParts(mixed $provider, array $metadata): array
    {
        $providerKey = $this->normalizedProvider($provider);
        $resolutionSource = $this->normalizedResolutionSource($metadata['provider_resolution_source'] ?? null);
        $readinessStatus = $this->normalizedReadinessStatus($metadata['provider_readiness_status'] ?? null);
        $configStatus = $this->normalizedConfigStatus($metadata['provider_config_status'] ?? null);
        $legacyContextMissing = ! array_key_exists('provider_resolution_source', $metadata)
            || ! array_key_exists('provider_readiness_status', $metadata);

        $usingFallback = $this->normalizedBoolean($metadata['provider_using_fallback_config'] ?? null);
        if ($usingFallback === null) {
            $usingFallback = $resolutionSource === 'fallback';
        }

        $runtimePath = $this->runtimePath($resolutionSource, $readinessStatus, $legacyContextMissing);

        $notes = [];
        if ($legacyContextMissing) {
            $notes[] = 'Provider context metadata is unavailable for this delivery row (legacy/unmigrated).';
        } elseif ($resolutionSource === 'fallback') {
            $notes[] = 'Delivery used fallback provider configuration.';
        }

        if ($readinessStatus === 'unsupported') {
            $notes[] = 'Delivery was attempted with a provider that does not support runtime sends in this app flow.';
        } elseif (in_array($readinessStatus, ['incomplete', 'not_configured', 'error'], true)) {
            $notes[] = 'Delivery context indicates provider setup was not fully ready at attempt time.';
        }

        return [
            'provider' => $providerKey,
            'provider_resolution_source' => $resolutionSource,
            'provider_resolution_source_label' => $this->resolutionSourceLabel($resolutionSource),
            'provider_readiness_status' => $readinessStatus,
            'provider_readiness_status_label' => $this->readinessStatusLabel($readinessStatus),
            'provider_config_status' => $configStatus,
            'provider_using_fallback_config' => $usingFallback,
            'provider_runtime_path' => $runtimePath,
            'provider_runtime_path_label' => $this->runtimePathLabel($runtimePath),
            'legacy_context_missing' => $legacyContextMissing,
            'notes' => array_values(array_unique($notes)),
        ];
    }

    /**
     * @return array{
     *   provider:string,
     *   provider_resolution_source:'tenant'|'fallback'|'none'|'unknown',
     *   provider_resolution_source_label:string,
     *   provider_readiness_status:'ready'|'unsupported'|'incomplete'|'error'|'not_configured'|'unknown',
     *   provider_readiness_status_label:string,
     *   provider_config_status:'configured'|'disabled'|'incomplete'|'error'|'not_configured'|'unknown',
     *   provider_using_fallback_config:bool,
     *   provider_runtime_path:string,
     *   provider_runtime_path_label:string,
     *   legacy_context_missing:bool,
     *   notes:array<int,string>
     * }
     */
    public function resolveFromDelivery(MarketingEmailDelivery $delivery): array
    {
        $metadata = is_array($delivery->metadata) ? $delivery->metadata : [];

        return $this->resolveFromParts($delivery->provider, $metadata);
    }

    public function resolutionSourceLabel(string $source): string
    {
        return match ($source) {
            'tenant' => 'Tenant configuration',
            'fallback' => 'Fallback configuration',
            'none' => 'No resolution source',
            default => 'Legacy / unavailable',
        };
    }

    public function readinessStatusLabel(string $status): string
    {
        return match ($status) {
            'ready' => 'Ready',
            'unsupported' => 'Unsupported runtime',
            'incomplete' => 'Incomplete setup',
            'error' => 'Validation error',
            'not_configured' => 'Not configured',
            default => 'Legacy / unavailable',
        };
    }

    public function runtimePathLabel(string $path): string
    {
        return match ($path) {
            'tenant_runtime_ready' => 'Tenant provider runtime',
            'fallback_runtime_ready' => 'Fallback provider runtime',
            'unsupported_runtime' => 'Unsupported runtime path',
            'blocked_runtime' => 'Blocked by readiness',
            default => 'Legacy / unavailable',
        };
    }

    protected function runtimePath(string $source, string $readinessStatus, bool $legacyContextMissing): string
    {
        if ($legacyContextMissing || $source === 'unknown' || $readinessStatus === 'unknown') {
            return 'legacy_or_unavailable';
        }

        if ($readinessStatus === 'ready' && $source === 'tenant') {
            return 'tenant_runtime_ready';
        }

        if ($readinessStatus === 'ready' && $source === 'fallback') {
            return 'fallback_runtime_ready';
        }

        if ($readinessStatus === 'unsupported') {
            return 'unsupported_runtime';
        }

        if (in_array($readinessStatus, ['incomplete', 'not_configured', 'error'], true)) {
            return 'blocked_runtime';
        }

        return 'legacy_or_unavailable';
    }

    protected function normalizedProvider(mixed $provider): string
    {
        $normalized = strtolower(trim((string) $provider));

        return $normalized !== '' ? $normalized : 'unknown';
    }

    /**
     * @return 'tenant'|'fallback'|'none'|'unknown'
     */
    protected function normalizedResolutionSource(mixed $source): string
    {
        $normalized = strtolower(trim((string) $source));

        return in_array($normalized, ['tenant', 'fallback', 'none'], true)
            ? $normalized
            : 'unknown';
    }

    /**
     * @return 'ready'|'unsupported'|'incomplete'|'error'|'not_configured'|'unknown'
     */
    protected function normalizedReadinessStatus(mixed $status): string
    {
        $normalized = strtolower(trim((string) $status));

        return in_array($normalized, ['ready', 'unsupported', 'incomplete', 'error', 'not_configured'], true)
            ? $normalized
            : 'unknown';
    }

    /**
     * @return 'configured'|'disabled'|'incomplete'|'error'|'not_configured'|'unknown'
     */
    protected function normalizedConfigStatus(mixed $status): string
    {
        $normalized = strtolower(trim((string) $status));

        return in_array($normalized, ['configured', 'disabled', 'incomplete', 'error', 'not_configured'], true)
            ? $normalized
            : 'unknown';
    }

    protected function normalizedBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (is_string($value)) {
            $trimmed = strtolower(trim($value));
            if ($trimmed === '') {
                return null;
            }
            if (in_array($trimmed, ['1', 'true', 'yes'], true)) {
                return true;
            }
            if (in_array($trimmed, ['0', 'false', 'no'], true)) {
                return false;
            }
        }

        return null;
    }
}


<?php

namespace App\Services\Marketing;

use App\Models\TenantMarketingSetting;
use App\Services\Pos\PosStore;
use Illuminate\Support\Facades\DB;

/** Resolves existing connections only; never creates an OAuth grant or guesses a company's token. */
class SquareConnectionContext
{
    public function __construct(private PosStore $store) {}

    public function available(int $tenantId): array
    {
        $items = [];
        $seenLegacy = [];
        try {
            foreach ($this->store->rows('connections', $tenantId)->get() as $row) {
                $items[] = ['id' => $row->id, 'kind' => 'original_square', 'merchant_id' => $row->merchant_id,
                    'location_id' => $row->provider_location_id, 'environment' => $row->environment, 'verified_at' => $row->verified_at ?? null, 'connection_generation' => $this->rowGeneration($row)];
                $key = $row->environment.':'.$row->merchant_id;
                if (! isset($seenLegacy[$key]) && filled(config('marketing.square.access_token')) && $row->environment === config('marketing.square.environment', 'production')) {
                    $seenLegacy[$key] = true;
                    $items[] = ['id' => 'legacy:'.$row->id, 'kind' => 'original_merchant_verified_legacy_reader', 'merchant_id' => $row->merchant_id, 'environment' => $row->environment, 'connection_generation' => $this->rowGeneration($row, (string) config('marketing.square.access_token'))];
                }
            }
        } catch (\App\Services\Pos\PosException) {
            // A tenant can retain its original marketing connection without using Everbranch Pay.
        }
        if ($raw = $this->setting($tenantId)) {
            $items[] = ['id' => $raw['connection_id'] ?? 'tenant-config', 'kind' => 'existing_square_config', 'environment' => $raw['environment'] ?? 'production', 'merchant_id' => $raw['merchant_id'] ?? null, 'connection_generation' => $this->settingGeneration($raw), 'status' => $raw['status'] ?? 'active'];
        }

        if (in_array($raw['status'] ?? '', ['disconnected', 'reconnect_required'], true) || in_array(data_get($raw ?? [], 'credential_refresh.status'), ['claimed', 'unknown'], true)) {
            foreach ($items as &$item) {
                $item['status'] = in_array($raw['status'] ?? '', ['disconnected', 'reconnect_required'], true) ? $raw['status'] : 'credential_review_required';
            }
        }

        return $items;
    }

    /** A source OAuth grant does not enroll a tenant in pre-existing import schedules. */
    public function requiresOwnerRefresh(int $tenantId, ?string $connectionId = null): bool
    {
        $raw = $this->setting($tenantId) ?? [];

        return ($raw['credential_flow'] ?? null) === 'core_source_authorization_code'
            && ($connectionId === null || $connectionId === 'tenant-config' || $connectionId === ($raw['connection_id'] ?? null));
    }

    private function local(int $tenantId, ?string $connectionId = null): ?array
    {
        $raw = $this->setting($tenantId);
        abort_if(in_array($raw['status'] ?? '', ['disconnected', 'reconnect_required'], true) || in_array(data_get($raw, 'credential_refresh.status'), ['claimed', 'unknown'], true), 409, 'The Square source connection needs reconnection or credential review.');
        if ($connectionId && $connectionId !== 'tenant-config' && $connectionId !== ($raw['connection_id'] ?? null)) {
            $legacy = str_starts_with($connectionId, 'legacy:');
            $originalId = $legacy ? substr($connectionId, 7) : $connectionId;
            $connection = $this->store->rows('connections', $tenantId)->where('id', $originalId)->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();
            abort_unless($connection, 404);
            $credentials = $legacy ? ['access_token' => config('marketing.square.access_token')] : $this->store->decrypt($connection->credentials);
            abort_unless(! empty($credentials['access_token']) && ($legacy || (! empty($credentials['expires_at']) && strtotime($credentials['expires_at']) > time())), 409, 'The original Square authorization expired. Reconnect the existing connection.');
            abort_if($legacy && $connection->environment !== config('marketing.square.environment', 'production'), 409, 'The original reader environment differs.');
            $config = ['access_token' => $credentials['access_token'], 'environment' => $connection->environment,
                'merchant_id' => $connection->merchant_id, 'location_ids' => [$connection->provider_location_id],
                'connection_id' => $connectionId, 'runtime_id' => $this->store->runtime(), 'connection_generation' => $this->rowGeneration($connection, $legacy ? $credentials['access_token'] : null)];
        } else {
            // Tenant-local configuration is an existing supported integration. A
            // missing row must never fall through to another company's global token.
            $raw = $this->setting($tenantId);
            if (! is_array($raw) || (empty($raw['location_ids']) && empty($raw['merchant_id']))) {
                return null;
            }
            $credentials = ! empty($raw['credentials']) ? $this->store->decrypt($raw['credentials']) : $raw;
            if (empty($credentials['access_token'])) {
                return null;
            }
            abort_if(! empty($credentials['expires_at']) && strtotime($credentials['expires_at']) <= time(), 409, 'The existing Square authorization expired.');
            $config = ['access_token' => $credentials['access_token'], 'environment' => $raw['environment'] ?? 'production',
                'merchant_id' => $raw['merchant_id'] ?? null, 'location_ids' => array_values($raw['location_ids'] ?? []),
                'connection_id' => $raw['connection_id'] ?? 'tenant-config', 'connection_generation' => $this->settingGeneration($raw)];
            if (($raw['credential_flow'] ?? null) === 'core_source_authorization_code') {
                $config['source_purpose'] = 'customer_history';
            }
            if (! empty($raw['base_url'])) {
                abort_unless(in_array(rtrim($raw['base_url'], '/'), ['https://connect.squareup.com', 'https://connect.squareupsandbox.com'], true), 409, 'Square origin needs review.');
            }
        }
        abort_unless(in_array($config['environment'], ['production', 'sandbox'], true), 409, 'Square environment needs review.');
        $config['base_url'] = $config['environment'] === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';

        return $config;
    }

    public function resolve(int $tenantId, ?string $connectionId = null): ?array
    {
        app(SquareCustomerOAuth::class)->renewIfNeeded($tenantId, $connectionId);
        $config = $this->local($tenantId, $connectionId);
        if (! $config) {
            return null;
        }
        $client = new SquareClient($config['access_token'], $config['base_url']);
        if (empty($config['merchant_id'])) {
            $location = $client->retrieveLocation((string) $config['location_ids'][0]);
            $config['merchant_id'] = $location['merchant_id'] ?? null;
        }
        abort_unless(filled($config['merchant_id']), 409, 'The original Square merchant is not verified.');
        $merchant = $client->retrieveMerchant($config['merchant_id']);
        abort_unless(($merchant['id'] ?? null) === $config['merchant_id'], 409, 'The Square authorization no longer belongs to the original merchant.');
        abort_if(TenantMarketingSetting::query()->where('key', 'square_config')->where('tenant_id', '!=', $tenantId)
            ->where('value->merchant_id', $config['merchant_id'])->where(function ($query) use ($config) {
                $query->where('value->environment', $config['environment']);
                if ($config['environment'] === 'production') {
                    $query->orWhereNull('value->environment');
                }
            })->exists(), 409, 'This original Square merchant belongs to a different workspace.');
        try {
            abort_if($this->store->db->table('pos_live_connections')->where('runtime_id', $this->store->runtime())->where('merchant_id', $config['merchant_id'])
                ->where('environment', $config['environment'])->where('tenant_id', '!=', $tenantId)->exists(), 409, 'This original Square merchant belongs to a different workspace.');
        } catch (\App\Services\Pos\PosException $error) {
            if ($error->blocker !== 'runtime_unconfigured') {
                throw $error;
            }
        }

        return $config;
    }

    public function publicBinding(array $config): array
    {
        return array_intersect_key($config, array_flip(['connection_id', 'connection_generation', 'runtime_id', 'merchant_id', 'environment', 'location_ids', 'source_purpose']));
    }

    /** Local admission for late responses; no provider HTTP inside customer locks. */
    public function assertCurrent(int $tenantId, array $binding): void
    {
        $current = $this->local($tenantId, $binding['connection_id'] ?? null);
        abort_unless($current && (string) $current['connection_generation'] === (string) ($binding['connection_generation'] ?? '')
            && $current['environment'] === ($binding['environment'] ?? null)
            && (empty($current['merchant_id']) || $current['merchant_id'] === ($binding['merchant_id'] ?? null)), 409, 'The original Square connection changed. Refresh its current state.');
    }

    private function setting(int $tenantId): ?array
    {
        $value = TenantMarketingSetting::query()->where('tenant_id', $tenantId)->where('key', 'square_config')->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first()?->value;

        return is_array($value) ? $value : null;
    }

    private function settingGeneration(array $value): int|string
    {
        return isset($value['generation']) ? (int) $value['generation'] : hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function rowGeneration(object $row, ?string $legacyToken = null): string
    {
        return hash('sha256', $row->credentials.'|'.($row->updated_at ?? '').'|'.($legacyToken === null ? '' : hash('sha256', $legacyToken)));
    }
}

<?php

namespace App\Services\Marketing;

use App\Models\MarketingImportRun;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit tenant-owned source-read policy. OAuth connection alone never enrolls a workspace. */
class SquareSourceRefresh
{
    public const KEY = 'square_source_refresh';

    public function status(int $tenant): array
    {
        $value = TenantMarketingSetting::forTenantId($tenant)->where('key', self::KEY)->value('value');
        $value = is_string($value) ? json_decode($value, true) : $value;

        return is_array($value) ? $value : ['enabled' => false, 'version' => Contract::hash(['unconfigured', $tenant]), 'lookback_days' => 14, 'create_unmatched' => false];
    }

    public function configure(int $tenant, User $actor, array $input): array
    {
        app(SquareCustomerIdentityService::class)->authorize($tenant, $actor);
        $data = validator($input, ['enabled' => 'required|boolean', 'expected_version' => 'required|string|size:64', 'connection_id' => 'nullable|string|max:120',
            'lookback_days' => 'required|integer|min:1|max:90', 'create_unmatched' => 'required|boolean', 'all_locations' => 'sometimes|boolean'])->validate();
        $config = $data['enabled'] ? app(SquareConnectionContext::class)->resolve($tenant, $data['connection_id'] ?? null) : null;
        $readerLocations = $config['location_ids'] ?? [];
        $discoveredLocations = [];
        if ($data['enabled']) {
            abort_unless($config && ! empty($config['location_ids']), 409, 'Review the original reader and locations before enabling refresh.');
            if (! empty($data['all_locations'])) {
                $rows = (new SquareClient($config['access_token'], $config['base_url']))->locations();
                foreach ($rows as $location) {
                    abort_unless(is_array($location) && is_string($location['id'] ?? null) && $location['id'] !== ''
                        && (! isset($location['merchant_id']) || $location['merchant_id'] === $config['merchant_id']), 409, 'Square location ownership needs review.');
                    $discoveredLocations[$location['id']] = ['id' => $location['id'], 'name' => $location['name'] ?? null, 'status' => $location['status'] ?? null];
                }
                abort_unless($discoveredLocations, 409, 'Square returned no original merchant locations.');
                ksort($discoveredLocations);
                $config['location_ids'] = array_keys($discoveredLocations);
            }
        }

        return DB::transaction(function () use ($tenant, $actor, $data, $config, $readerLocations, $discoveredLocations): array {
            Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            app(SquareCustomerIdentityService::class)->authorize($tenant, User::findOrFail($actor->id));
            $current = $this->status($tenant);
            abort_unless(hash_equals($current['version'], $data['expected_version']), 409, 'Source refresh settings changed. Review the current settings.');
            if ($config) {
                app(SquareConnectionContext::class)->assertCurrent($tenant, app(SquareConnectionContext::class)->publicBinding($config));
            }
            $value = ['enabled' => (bool) $data['enabled'], 'binding' => $config ? $this->binding($config) : ($current['binding'] ?? null),
                'lookback_days' => (int) $data['lookback_days'], 'create_unmatched' => (bool) $data['create_unmatched'], 'reviewed_by' => $actor->id,
                'reviewed_at' => now()->toIso8601String(), 'historical_source_only' => true];
            if ($config && ! empty($data['all_locations'])) {
                sort($readerLocations);
                $value['all_locations'] = true;
                $value['reader_location_ids'] = $readerLocations;
                $value['discovered_locations'] = array_values($discoveredLocations);
            }
            $value['version'] = $this->policyHash($value);
            TenantMarketingSetting::updateOrCreate(['tenant_id' => $tenant, 'key' => self::KEY], ['value' => $value]);
            MarketingImportRun::create(['tenant_id' => $tenant, 'created_by' => $actor->id, 'type' => 'square_source_refresh_settings', 'source_label' => 'owner_reviewed_original_reader',
                'status' => 'completed', 'started_at' => now(), 'finished_at' => now(), 'summary' => ['before' => $current, 'after' => $value]]);

            return $value;
        }, 3);
    }

    /** Fast webhook admission uses local ownership/connection state only; no provider HTTP. */
    public function webhookTargets(string $merchant, string $environment, ?string $location): array
    {
        $targets = [];
        foreach (TenantMarketingSetting::where('key', self::KEY)->where('value->enabled', true)->where('value->binding->merchant_id', $merchant)->where('value->binding->environment', $environment)->get() as $setting) {
            try {
                $policy = $this->assertPolicyCurrent((int) $setting->tenant_id, (string) ($setting->value['version'] ?? ''));
                $binding = $policy['binding'];
                if ($binding['merchant_id'] === $merchant && $binding['environment'] === $environment
                    && ($location === null || in_array($location, $binding['location_ids'], true))) {
                    $targets[] = ['tenant_id' => (int) $setting->tenant_id, 'binding' => [...$binding, 'policy_version' => $policy['version']]];
                }
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                if (! in_array($error->getStatusCode(), [403, 409, 404, 503], true)) {
                    throw $error;
                }
            }
        }

        return $targets;
    }

    public function assertPolicyCurrent(int $tenant, string $version): array
    {
        $policy = $this->status($tenant);
        abort_unless(($policy['enabled'] ?? false) === true && preg_match('/^[a-f0-9]{64}$/D', (string) ($policy['version'] ?? ''))
            && hash_equals((string) $policy['version'], $version) && hash_equals($policy['version'], $this->policyHash(array_diff_key($policy, ['version' => true])))
            && ($policy['historical_source_only'] ?? false) === true, 409, 'Automatic Square source refresh is not enabled for this workspace.');
        $actor = User::find((int) ($policy['reviewed_by'] ?? 0));
        abort_unless($actor, 403);
        app(SquareCustomerIdentityService::class)->authorize($tenant, $actor);
        $binding = $policy['binding'] ?? [];
        $reader = collect(app(SquareConnectionContext::class)->available($tenant))->firstWhere('id', $binding['connection_id'] ?? '');
        abort_unless($reader && ! in_array($reader['status'] ?? 'active', ['disconnected', 'reconnect_required', 'credential_review_required'], true)
            && ($reader['merchant_id'] ?? null) === ($binding['merchant_id'] ?? null) && ($reader['environment'] ?? null) === ($binding['environment'] ?? null), 409, 'The original Square reader needs review.');
        $locations = $reader['location_ids'] ?? (isset($reader['location_id']) ? [$reader['location_id']] : []);
        sort($locations);
        $expected = ! empty($policy['all_locations']) ? ($policy['reader_location_ids'] ?? []) : ($binding['location_ids'] ?? []);
        sort($expected);
        abort_unless($expected && $locations === $expected, 409, 'Square locations changed. Review source refresh settings.');

        return $policy;
    }

    /** Only a recorded owner policy can widen a source reader beyond its original register. */
    public function configForPolicy(int $tenant, string $version, array $config): array
    {
        $policy = $this->assertPolicyCurrent($tenant, $version);
        $binding = $policy['binding'];
        foreach (['connection_id', 'merchant_id', 'environment', 'runtime_id'] as $key) {
            abort_unless(($config[$key] ?? null) === ($binding[$key] ?? null), 409, 'Source refresh belongs to a different original reader.');
        }
        if (! empty($policy['all_locations'])) {
            abort_unless(is_array($binding['location_ids'] ?? null) && $binding['location_ids'], 409);
            $config['location_ids'] = $binding['location_ids'];
            $config['source_purpose'] = 'customer_history';
            $config['all_locations_reviewed'] = true;
        }

        return $config;
    }

    public function refreshObject(int $tenant, array $originalBinding, string $kind, string $providerId, string $requestId): array
    {
        abort_unless(Str::isUuid($requestId), 422);
        $policy = $this->assertPolicyCurrent($tenant, $this->status($tenant)['version']);
        $binding = $policy['binding'];
        $original = array_intersect_key($originalBinding, array_flip(['connection_id', 'merchant_id', 'environment', 'location_ids', 'runtime_id']));
        if (is_array($original['location_ids'] ?? null)) {
            sort($original['location_ids']);
        }
        $expected = $binding;
        ksort($original);
        ksort($expected);
        abort_unless(($originalBinding['policy_version'] ?? null) === $policy['version']
            && $original === $expected
            && ($originalBinding['merchant_id'] ?? null) === $binding['merchant_id'] && ($originalBinding['environment'] ?? null) === $binding['environment']
            && (! isset($originalBinding['location_id']) || in_array($originalBinding['location_id'], $binding['location_ids'], true))
            && (! isset($originalBinding['runtime_id']) || ! isset($binding['runtime_id']) || $originalBinding['runtime_id'] === $binding['runtime_id']), 409, 'The webhook does not match the reviewed original reader.');

        return Cache::lock('square-source-object:'.hash('sha256', $tenant.':'.$requestId), 120)->block(5, function () use ($tenant, $kind, $providerId, $requestId, $policy): array {
            $prior = MarketingImportRun::forTenantId($tenant)->where('type', 'square_source_object_sync')->where('summary->source_root_request_id', $requestId)->latest('id')->first();
            $context = ['kind' => $kind, 'source_id_hash' => hash('sha256', $providerId)];
            if ($prior) {
                abort_unless(($prior->summary['request_context'] ?? null) === $context, 409, 'The original source request contains a different provider object.');
                if ($prior->status === 'completed') {
                    return ['status' => 'completed', 'run_id' => $prior->id, 'summary' => $prior->summary, 'replayed' => true];
                }
            }
            $attempt = $prior ? $this->uuid($requestId.':retry:'.$prior->id) : $requestId;

            return app(SquareMarketingSyncService::class)->refreshSourceObject($kind, $providerId, [...$this->options($tenant, $policy),
                'request_id' => $attempt, 'source_root_request_id' => $requestId]);
        });
    }

    public function runTenant(int $tenant, int $limit = 500): array
    {
        $policy = $this->assertPolicyCurrent($tenant, $this->status($tenant)['version']);
        $anchor = now()->startOfMinute();
        $result = [];
        foreach (['customers' => 'syncCustomers', 'orders' => 'syncOrders', 'payments' => 'syncPayments'] as $kind => $method) {
            $options = [...$this->options($tenant, $policy), 'limit' => max(1, min(500, $limit)), 'since' => $anchor->copy()->subDays($policy['lookback_days']),
                'request_id' => $this->uuid($tenant.':'.$policy['version'].':'.$kind.':'.$anchor->toIso8601String())];
            $prior = MarketingImportRun::forTenantId($tenant)->where('type', 'square_'.$kind.'_sync')->where('summary->source_refresh_policy_version', $policy['version'])->latest('id')->first();
            if ($prior && ($prior->summary['request_id'] ?? null) === $options['request_id']) {
                $result[$kind] = ['status' => $prior->status, 'run_id' => $prior->id, 'summary' => $prior->summary, 'replayed' => true];

                continue;
            }
            if ($prior && (data_get($prior->summary, 'checkpoint.cursor') || ($prior->status === 'failed' && ($prior->summary['retry_page'] ?? false)))) {
                $reader = collect(app(SquareConnectionContext::class)->available($tenant))->firstWhere('id', $policy['binding']['connection_id']);
                if ((string) ($reader['connection_generation'] ?? '') === (string) data_get($prior->summary, 'source_binding.connection_generation', '')) {
                    $options['resume_run_id'] = $prior->id;
                } else {
                    // Renewal can retain the reviewed original merchant and policy.
                    // Keep the old checkpoint as evidence and begin a fresh recent
                    // window under the current generation instead of replaying it.
                    $options['generation_restart_from_run_id'] = $prior->id;
                }
            }
            $result[$kind] = app(SquareMarketingSyncService::class)->{$method}($options);
        }
        if (! empty($policy['all_locations'])) {
            $result['customer_references'] = app(SquareCustomerReferenceReconciliation::class)->scheduled($tenant, User::findOrFail($policy['reviewed_by']), $policy['version'],
                $this->uuid($tenant.':'.$policy['version'].':customer-references:'.$anchor->toIso8601String()), max(1, min(500, $limit)));
        }

        return $result;
    }

    private function options(int $tenant, array $policy): array
    {
        return ['tenant_id' => $tenant, 'manual_refresh' => true, 'created_by' => $policy['reviewed_by'], 'connection_id' => $policy['binding']['connection_id'],
            'historical_source_only' => true, 'create_unmatched' => $policy['create_unmatched'], 'source_refresh_policy_version' => $policy['version'], 'updated_since' => true];
    }

    private function binding(array $config): array
    {
        $binding = array_intersect_key($config, array_flip(['connection_id', 'merchant_id', 'environment', 'location_ids', 'runtime_id']));
        sort($binding['location_ids']);

        return $binding;
    }

    private function uuid(string $value): string
    {
        $hash = hash('sha256', $value);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-4'.substr($hash, 13, 3).'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }

    private function policyHash(array $value): string
    {
        $normalize = function (array $row) use (&$normalize): array {
            if (! array_is_list($row)) {
                ksort($row);
            }
            foreach ($row as &$item) {
                if (is_array($item)) {
                    $item = $normalize($item);
                }
            }
            unset($item);

            return $row;
        };

        return Contract::hash($normalize($value));
    }
}

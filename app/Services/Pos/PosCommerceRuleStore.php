<?php

declare(strict_types=1);

namespace App\Services\Pos;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Explicitly scoped draft configuration; saving rules does not activate checkout. */
final class PosCommerceRuleStore
{
    public function __construct(private PosCommerceRules $rules, private PosStore $pos) {}

    public function get(array $scope): array
    {
        $this->authorize($scope);
        $row = DB::table('tenant_commerce_rule_sets')->where('tenant_id', $scope['tenant_id'])->first();
        if (! $row) {
            return $this->rules->validate([]);
        }
        $rules = json_decode($row->rules, true, 512, JSON_THROW_ON_ERROR);
        $result = $this->rules->validate($rules);
        if (! hash_equals($row->version, $result['version'])) {
            throw new PosException('checkout_rules_corrupt', 503);
        }

        return $result;
    }

    public function save(array $scope, array $rule, string $expectedVersion): array
    {
        return $this->mutate($scope, fn ($rules) => $this->rules->save($rules, $rule, $expectedVersion));
    }

    public function delete(array $scope, string $id, string $expectedVersion): array
    {
        return $this->mutate($scope, fn ($rules) => $this->rules->delete($rules, $id, $expectedVersion));
    }

    /** Draft and prior review are displayed together so an administrator can review an exact version. */
    public function reviewStatus(array $scope, string $register): array
    {
        $this->authorize($scope);
        $row = $this->pos->rows('registers', $scope['tenant_id'])->where('id', $register)->where('active', true)->first();
        if (! $row) {
            throw new PosException('register_unavailable', 404);
        }
        $settings = $this->pos->decrypt($row->configuration);

        return [...$this->get($scope), 'reviewed_version' => $settings['checkout_rules_reviewed_version'] ?? null,
            'checkout_enabled' => config('pos_live.checkout_rules_enabled') === true];
    }

    /** Approve exactly the draft and previous register review the administrator observed. */
    public function publish(array $scope, string $register, string $version, ?string $previousVersion, bool $confirmed): array
    {
        $this->authorize($scope);
        if (! $confirmed) {
            throw new PosException('checkout_rules_confirmation_required', 422);
        }

        return $this->withLock($scope['tenant_id'], fn () => $this->pos->db->transaction(function () use ($scope, $register, $version, $previousVersion): array {
            $tenant = $this->pos->db->table('tenants')->where('id', $scope['tenant_id'])->lockForUpdate()->first();
            if (! $tenant) {
                throw new PosException('tenant_unavailable', 404);
            }
            $row = $this->pos->rows('registers', $scope['tenant_id'])->where('id', $register)->where('active', true)->lockForUpdate()->first();
            if (! $row) {
                throw new PosException('register_unavailable', 404);
            }
            $draft = $this->get($scope);
            $settings = $this->pos->decrypt($row->configuration);
            $current = $settings['checkout_rules_reviewed_version'] ?? null;
            if (! hash_equals($draft['version'], $version) || $current !== $previousVersion) {
                throw new PosException('checkout_rules_changed');
            }
            foreach ($draft['rules'] as $rule) {
                if ($rule['kind'] !== 'addon') {
                    continue;
                }
                $found = app(\App\Services\Inventory\StockLedger::class)->rows('items', $scope['tenant_id'])
                    ->whereIn('id', $rule['eligible_item_ids'])->where('active', true)->count();
                if ($found !== count($rule['eligible_item_ids'])) {
                    throw new PosException('checkout_rule_item_unavailable', 422);
                }
            }
            if ($current !== $version || ($settings['checkout_rules_review']['version'] ?? null) !== $version
                || ($settings['checkout_rules_review']['rules'] ?? null) !== $draft['rules']) {
                $settings['checkout_rules_reviewed_version'] = $version;
                $settings['checkout_rules_review'] = ['version' => $version, 'rules' => $draft['rules'],
                    'actor_id' => $scope['actor_id'], 'reviewed_at' => now()->toIso8601String()];
                $this->pos->rows('registers', $scope['tenant_id'])->where('id', $register)->update(['configuration' => $this->pos->encrypt($settings)]);
                $this->pos->event($scope['tenant_id'], 'checkout_rules', 'checkout_rules_published', [
                    'register_id' => $register, 'actor_id' => $scope['actor_id'], 'before' => $current,
                    'version' => $version, 'rules' => $draft['rules'],
                ]);
            }

            return [...$draft, 'reviewed_version' => $version, 'checkout_enabled' => config('pos_live.checkout_rules_enabled') === true];
        }, 3));
    }

    private function mutate(array $scope, callable $operation): array
    {
        $this->authorize($scope);

        return $this->withLock($scope['tenant_id'], fn () => DB::transaction(function () use ($scope, $operation): array {
            $tenant = DB::table('tenants')->where('id', $scope['tenant_id'])->lockForUpdate()->first();
            if (! $tenant) {
                throw new PosException('tenant_unavailable', 404);
            }
            $before = $this->get($scope);
            $after = $operation($before['rules']);
            foreach ($after['rules'] as $rule) {
                if ($rule['kind'] !== 'addon') {
                    continue;
                }
                $items = $rule['eligible_item_ids'];
                $found = app(\App\Services\Inventory\StockLedger::class)->rows('items', $scope['tenant_id'])
                    ->whereIn('id', $items)->where('active', true)->count();
                if ($found !== count($items)) {
                    throw new PosException('checkout_rule_item_unavailable', 422);
                }
            }
            $values = ['rules' => json_encode($after['rules'], JSON_THROW_ON_ERROR), 'version' => $after['version'],
                'updated_by' => $scope['actor_id'], 'updated_at' => now()];
            $row = DB::table('tenant_commerce_rule_sets')->where('tenant_id', $scope['tenant_id'])->first();
            if ($row) {
                DB::table('tenant_commerce_rule_sets')->where('tenant_id', $scope['tenant_id'])->update($values);
            } else {
                DB::table('tenant_commerce_rule_sets')->insert([...$values, 'tenant_id' => $scope['tenant_id'], 'created_at' => now()]);
            }
            $this->pos->event($scope['tenant_id'], 'checkout_rules', 'checkout_rules_updated', [
                'actor_id' => $scope['actor_id'], 'before' => $before['version'], 'after' => $after['version'],
            ]);

            return $after;
        }, 3));
    }

    public function lockKey(int $tenant): string
    {
        return 'pos-offers-'.hash('sha256', $this->pos->runtime().':'.$tenant);
    }

    public function withLock(int $tenant, callable $operation): mixed
    {
        try {
            return Cache::lock($this->lockKey($tenant), 180)->block(5, $operation);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            throw new PosException('checkout_rules_busy', 503);
        }
    }

    private function authorize(array $scope): void
    {
        if (($scope['admin'] ?? false) !== true || ! is_int($scope['tenant_id'] ?? null) || $scope['tenant_id'] < 1
            || ! is_int($scope['actor_id'] ?? null) || $scope['actor_id'] < 1) {
            throw new PosException('organization_admin_required', 403);
        }
    }
}

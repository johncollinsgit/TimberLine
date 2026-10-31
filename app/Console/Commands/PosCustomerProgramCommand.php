<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Pos\PosCustomerRewards;
use App\Services\Pos\PosException;
use App\Services\Pos\PosStore;
use Illuminate\Console\Command;

/** Binds an already configured tenant program; never creates rates or publishes a program. */
final class PosCustomerProgramCommand extends Command
{
    protected $signature = 'pos:customer-program {tenant} {register} {--actor=} {--expected-policy=} {--apply}';

    protected $description = 'Preview/bind tenant-approved show earning and online redemption to one register';

    public function handle(PosStore $store, PosCustomerRewards $rewards): int
    {
        $tenant = (int) $this->argument('tenant');
        $actor = User::query()->find((int) $this->option('actor'));
        $member = $store->db->table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $actor?->id)->first();
        if (! $actor?->is_active || ! $actor->hasVerifiedEmail() || ! $member?->membership_active
            || ! in_array($member->role, ['owner', 'tenant_owner', 'admin'], true)) {
            throw new PosException('existing_entitled_owner_required', 403);
        }
        $register = $store->rows('registers', $tenant)->where('id', $this->argument('register'))->firstOrFail();
        $current = $rewards->policy($tenant);
        $rules = $current['policy']['earning_rules'] ?? [];
        $blockers = [];
        if (! $current) {
            $blockers[] = 'tenant_owned_program_and_policy_required';
        }
        if (($rules['rewardable_channels'] ?? $current['program']['rewardable_channels'] ?? '') !== 'show_issued_online_redeemed'
            || ($rules['event_rewards_enabled'] ?? false) !== true) {
            $blockers[] = 'enable_show_earning_in_existing_tenant_program';
        }
        if (($current['access']['launch_state'] ?? 'published') !== 'published' || ($current['access']['test_mode'] ?? false)) {
            $blockers[] = 'published_live_tenant_program_required';
        }
        $this->line(json_encode(['register_id' => $register->id, 'policy_version' => $current['version'] ?? null,
            'earning_rules' => $rules, 'redemption' => 'online_only', 'refunds' => 'recalculate_original_rules',
            'thresholds' => 'highest_per_purchase', 'shortfalls' => 'review_without_blocking_refund',
            'collection_enabled_by_this_command' => false, 'blockers' => $blockers], JSON_PRETTY_PRINT));
        if ($blockers) {
            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        if (! hash_equals($current['version'], (string) $this->option('expected-policy'))) {
            throw new PosException('exact_reviewed_policy_required');
        }
        $store->db->transaction(function () use ($store, $rewards, $tenant, $actor, $register, $current): void {
            $row = $store->rows('registers', $tenant)->where('id', $register->id)->lockForUpdate()->firstOrFail();
            if (($rewards->policy($tenant)['version'] ?? null) !== $current['version']) {
                throw new PosException('customer_rewards_policy_changed');
            }
            $before = $store->decrypt($row->configuration);
            $after = [...$before, 'customer_required' => ! app(\App\Services\Pos\StockPosCommerce::class)->enabled($row), 'customer_contract_version' => 1,
                'customer_rewards' => ['enabled' => true, 'policy_version' => $current['version'],
                    'threshold_mode' => 'highest_per_purchase', 'reviewed_by' => $actor->id, 'reviewed_at' => now()->toIso8601String()]];
            $store->rows('registers', $tenant)->where('id', $row->id)->update(['configuration' => $store->encrypt($after)]);
            $store->event($tenant, $row->id, 'customer_program_bound', ['actor_id' => $actor->id,
                'before' => $before['customer_rewards'] ?? null, 'after' => $after['customer_rewards']]);
        }, 3);

        return self::SUCCESS;
    }
}

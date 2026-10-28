<?php

namespace App\Console\Commands;

use App\Models\CandleCashTask;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\BirthdayRewardEngineService;
use App\Services\Shopify\ShopifyEmbeddedRewardsService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Services\Tenancy\TenantMarketingSettingsResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConfigureBirthdayClub extends Command
{
    protected $signature = 'birthday-club:configure {--tenant-id=} {--apply : Activate the reviewed Birthday Club and retire future signup bonuses}';

    protected $description = 'Preview or apply the Modern Forestry Birthday Club policy without changing rewards or sending messages.';

    public function handle(BirthdayRewardEngineService $engine, TenantMarketingSettingsResolver $settings, ShopifyEmbeddedRewardsService $rewards, LandlordOperatorActionAuditService $audit): int
    {
        $tenantId = filter_var($this->option('tenant-id'), FILTER_VALIDATE_INT);
        $store = $tenantId ? ShopifyStore::query()->where('tenant_id', $tenantId)->where('store_key', 'retail')->where('shop_domain', 'modernforestry.myshopify.com')->first() : null;
        $config = $tenantId ? $engine->rewardConfig($tenantId) : [];
        if (! $store || ! ($config['enabled'] ?? false) || ($config['reward_type'] ?? '') !== 'discount_code' || (float) ($config['reward_value'] ?? 0) !== 10.0 || (int) ($config['claim_window_days_after'] ?? 0) !== 30) {
            $this->error('Requires the verified Modern Forestry retail tenant and its existing enabled $10/30-day coupon policy.');

            return self::FAILURE;
        }
        $before = $settings->array('birthday_club_config', $tenantId);
        $after = [...$before, 'enabled' => true, 'signup_bonus_enabled' => false, 'canonical_path' => '/pages/birthday-club', 'cutover_at' => $before['cutover_at'] ?? now()->toIso8601String()];
        if ($this->option('apply')) {
            DB::transaction(function () use ($tenantId, $before, $after, $rewards, $audit): void {
                Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
                TenantMarketingSetting::query()->updateOrCreate(['tenant_id' => $tenantId, 'key' => 'birthday_club_config'], ['value' => $after]);
                $task = CandleCashTask::query()->where('handle', 'birthday-signup')->first();
                if ($task) {
                    $rewards->updateEarnRule($task, ['title' => $task->title, 'description' => $task->description, 'candle_cash_value' => 0, 'enabled' => false, 'sort_order' => $task->display_order], $tenantId);
                }
                $audit->record(tenantId: $tenantId, actorUserId: null, actionType: 'birthday_club_consolidation', targetType: 'tenant_marketing_setting', targetId: 'birthday_club_config', context: ['source' => 'owner_approved_birthday_club_consolidation'], beforeState: $before, afterState: $after, result: ['rewards_changed' => 0, 'messages_sent' => 0, 'signup_bonus_retired' => true]);
            });
            $settings->flushArrayCache();
        }
        $this->line(json_encode(['tenant_id' => $tenantId, 'applied' => (bool) $this->option('apply'), 'before' => $before, 'after' => $after, 'annual_policy' => $config], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}

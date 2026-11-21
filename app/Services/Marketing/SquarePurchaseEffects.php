<?php

namespace App\Services\Marketing;

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Explicitly enrolled confirmed purchases -> shared identity, attribution and the existing wallet. */
class SquarePurchaseEffects
{
    public const SETTING = 'square_purchase_effects';

    public function policy(int $tenant): ?array
    {
        $policy = TenantMarketingSetting::forTenantId($tenant)->where('key', self::SETTING)->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first()?->value;

        return is_array($policy) && ($policy['enabled'] ?? false) === true ? $policy : null;
    }

    public function needsReconcile(int $tenant, string $orderId): bool
    {
        $policy = $this->policy($tenant);
        $order = $policy ? SquareOrder::forTenantId($tenant)->where('square_order_id', $orderId)->first() : null;
        if (! $order?->closed_at || $order->closed_at->lt(CarbonImmutable::parse($policy['effective_from']))) {
            return false;
        }
        $link = MarketingProfileLink::forTenantId($tenant)->where('source_type', 'square_order')->where('source_id', $orderId)->first();
        $state = $link?->source_meta['purchase_effects'] ?? [];
        $payments = \App\Models\SquarePayment::forTenantId($tenant)->where('square_order_id', $orderId)->get();

        return ! $state || ($state['source_revision'] ?? null) !== $this->sourceRevision($order, $payments)
            || ($state['touch_revision'] ?? null) !== $this->touchRevision($tenant, (int) $link->marketing_profile_id, $order);
    }

    private function sourceRevision(SquareOrder $order, $payments): string
    {
        return hash('sha256', json_encode([$order->raw_payload, $order->state, $order->total_money_amount,
            $payments->sortBy('square_payment_id')->map(fn ($p) => [$p->square_payment_id, $p->status, $p->amount_money, $p->raw_payload])->values()->all()]));
    }

    private function touchRevision(int $tenant, int $profile, SquareOrder $order): string
    {
        $ids = app(CustomerMarketingHistory::class)->aliasIds($tenant, $profile);

        return hash('sha256', json_encode(MarketingMessageDelivery::forTenantId($tenant)->whereIn('marketing_profile_id', $ids)
            ->where('sent_at', '<=', $order->closed_at)->where('sent_at', '>=', $order->closed_at->copy()->subDays(7))
            ->orderBy('id')->get(['id', 'campaign_id', 'send_status', 'sent_at', 'delivered_at'])->toArray()));
    }

    public function reconcile(int $tenant, string $orderId, bool $dryRun = false): array
    {
        $policy = $this->policy($tenant);
        if (! $policy) {
            return ['status' => 'disabled'];
        }
        $actor = User::findOrFail((int) ($policy['reviewed_by'] ?? 0));
        app(SquareCustomerIdentityService::class)->authorize($tenant, $actor);
        $work = function () use ($tenant, $orderId, $policy, $actor, $dryRun): array {
            abort_unless($this->policy($tenant) === $policy, 409, 'Purchase enrollment changed.');
            app(SquareCustomerIdentityService::class)->authorize($tenant, $actor);
            $context = app(SquarePurchaseCustomerLinker::class)->reconcile($tenant, $orderId, $dryRun);
            if ($context['status'] !== 'ready') {
                return $context;
            }
            $order = $context['order'];
            $binding = $context['binding'];
            $profile = $context['profile'];
            foreach (['merchant_id', 'environment'] as $field) {
                abort_unless(($policy[$field] ?? null) === $binding[$field], 409, 'Purchase enrollment belongs to its original merchant.');
            }
            if ($binding['environment'] !== 'production' || ! in_array($order->location_id, $policy['location_ids'] ?? [], true)) {
                return ['status' => 'excluded', 'reason' => 'unreviewed_purchase_source'];
            }
            // Original Pay operations own their rewards. A Square mirror cannot earn again.
            if ($this->payMirror($tenant, $context)) {
                return ['status' => 'excluded', 'reason' => 'original_pay_purchase'];
            }
            $link = MarketingProfileLink::forTenantId($tenant)->where('source_type', 'square_order')->where('source_id', $orderId)->when(! $dryRun, fn ($q) => $q->lockForUpdate())->first();
            $previous = $link?->source_meta['purchase_effects'] ?? [];
            if (isset($previous['profile_id']) && app(CanonicalMarketingProfileResolver::class)->byId($tenant, $previous['profile_id'])?->id !== $profile->id) {
                return ['status' => 'review', 'reason' => 'previous_reward_owner_changed'];
            }
            $event = app(EventCampaignResults::class)->eventForOrder($tenant, $order);
            $backfillTerms = $event ? ($policy['backfill_terms'][$event->id] ?? null) : null;
            $terms = $previous['terms'] ?? ($order->closed_at->lt(CarbonImmutable::parse($policy['activated_at'])) && $backfillTerms
                ? $backfillTerms : $this->termsAt($tenant, CarbonImmutable::instance($order->closed_at)));
            $refund = app(SquarePurchaseEvidence::class)->recordedRefunds($order, $context['payments']);
            $eligible = $refund !== null && $refund >= ($previous['refund_minor'] ?? 0) ? $this->merchandise($order, $refund) : null;
            $inWindow = $order->closed_at->gte(CarbonImmutable::parse($policy['effective_from']));
            $inWindow = $inWindow && ($order->closed_at->gte(CarbonImmutable::parse($policy['activated_at']))
                || ($event && in_array($event->id, $policy['backfill_event_ids'] ?? [], true)));
            $rules = $terms['policy']['earning_rules'] ?? [];
            $rewardReady = $inWindow && $eligible !== null && isset($rules['purchase_earn_bps'])
                && ($rules['enable_order_based_earning'] ?? false) && ($rules['event_rewards_enabled'] ?? false)
                && ($terms['access']['launch_state'] ?? null) === 'published' && ! ($terms['access']['test_mode'] ?? true);
            $desired = $rewardReady ? PurchaseRewardCalculator::percentageMinor($eligible, (int) $rules['purchase_earn_bps']) : null;
            $key = substr(hash('sha256', json_encode([$tenant, $binding['merchant_id'], $binding['environment'], $orderId])), 0, 48);
            $posted = (int) round(CandleCashTransaction::query()->where('marketing_profile_id', $profile->id)->where('source', 'square_purchase')
                ->where('source_id', 'like', $key.':%')->when(! $dryRun, fn ($q) => $q->lockForUpdate())->get()->sum('candle_cash_delta') * 100);
            $balance = CandleCashBalance::where('marketing_profile_id', $profile->id)->lockForUpdate()->first();
            $balanceMinor = (int) round(($balance?->balance ?? 0) * 100);
            $ceiling = $previous['award_ceiling_minor'] ?? null;
            if ($desired !== null) {
                $cap = $terms['policy']['value_model']['max_wallet_balance_dollars'] ?? null;
                $ceiling ??= $cap === null ? $desired : min($desired, max(0, (int) round($cap * 100) - $balanceMinor));
                $desired = min($desired, $ceiling);
            }
            $shortfall = $desired !== null && $desired < $posted && $balanceMinor < $posted - $desired;
            $state = ['source_revision' => $this->sourceRevision($order, $context['payments']), 'touch_revision' => $this->touchRevision($tenant, $profile->id, $order), 'version' => 1, 'profile_id' => (int) $profile->id, 'terms' => $terms, 'eligible_minor' => $eligible,
                'refund_minor' => max($previous['refund_minor'] ?? 0, $refund ?? 0), 'award_ceiling_minor' => $ceiling, 'reward_target_minor' => $desired, 'reward_posted_minor' => $posted,
                'reward_status' => ! $inWindow ? 'outside_earning_window' : ($rewardReady ? 'ready' : 'review_required'),
                'bonus_status' => 'requires_complete_prior_purchase_and_dated_membership_evidence', 'event_record_id' => $event?->id];
            if (! $dryRun && ! $shortfall && $desired !== null && $desired !== $posted) {
                $result = app(CandleCashService::class)->addRewardAmountIdempotent($profile, ($desired - $posted) / 100,
                    'square_purchase', $key.':'.substr(hash('sha256', json_encode([$desired, $posted, $refund, $eligible])), 0, 32),
                    $desired > $posted ? 'earn' : 'adjustment', $desired > $posted ? 'Confirmed Square purchase earning' : 'Confirmed Square purchase refund adjustment');
                $state['reward_posted_minor'] = $desired;
                $state['transaction_id'] = $result['transaction_id'];
            } elseif (isset($previous['transaction_id'])) {
                $state['transaction_id'] = $previous['transaction_id'];
            }
            if ($desired !== null) {
                $state['reward_status'] = $shortfall ? 'refund_balance_review_required' : ($dryRun ? 'preview' : ($ceiling === 0 ? 'paused_at_wallet_cap' : 'applied'));
            }
            $state['attribution'] = $this->attribute($tenant, $profile, $order, $eligible, $event?->id, $dryRun);
            if (! $dryRun && $link && $state !== $previous) {
                $link->update(['source_meta' => array_replace((array) $link->source_meta, ['purchase_effects' => $state])]);
            }

            return ['status' => $dryRun ? 'preview' : 'processed', 'profile_id' => $profile->id, 'order_id' => $orderId, 'effects' => $state];
        };

        return $dryRun ? $work() : app(CustomerAccountFence::class)->forTenant($tenant, $work);
    }

    public function termsAt(int $tenant, CarbonImmutable $at): array
    {
        $rows = TenantMarketingSetting::forTenantId($tenant)->whereIn('key', ['candle_cash_policy_versions', 'candle_cash_policy_config', 'candle_cash_program_config', 'candle_cash_access_state'])->get()->keyBy('key');
        $versions = collect($rows->get('candle_cash_policy_versions')?->value['items'] ?? [])
            ->filter(fn ($v) => isset($v['saved_at']) && CarbonImmutable::parse($v['saved_at'])->lte($at))->sortByDesc('version');
        $version = $versions->first();
        if (! $version) {
            return [];
        }

        return ['version' => $version['version'], 'saved_at' => $version['saved_at'], 'policy' => $version['policy_snapshot'],
            'access' => $version['policy_snapshot']['access_state'] ?? []];
    }

    public function merchandise(SquareOrder $order, ?int $refund): ?int
    {
        if ($order->total_money_currency !== 'USD' || $refund === null) {
            return null;
        }
        if ($refund >= $order->total_money_amount) {
            return 0;
        }
        $raw = $order->raw_payload ?? [];
        $net = $refund > 0 ? ($raw['net_amounts'] ?? []) : $raw;
        $read = function ($key) use ($net): ?int {
            $value = $net[$key] ?? null;

            return is_array($value) && ($value['currency'] ?? null) === 'USD' && is_int($value['amount'] ?? null) && $value['amount'] >= 0 ? $value['amount'] : null;
        };
        $total = $read($refund > 0 ? 'total_money' : 'total_money');
        $tax = $read($refund > 0 ? 'tax_money' : 'total_tax_money');
        $tip = $read($refund > 0 ? 'tip_money' : 'total_tip_money');
        $charges = $read($refund > 0 ? 'service_charge_money' : 'total_service_charge_money');
        if ($total === null || $tax === null || $tip === null || $charges === null
            || $total !== (int) $order->total_money_amount - $refund || $total < $tax + $tip + $charges) {
            return null;
        }

        return $total - $tax - $tip - $charges;
    }

    private function attribute(int $tenant, MarketingProfile $profile, SquareOrder $order, ?int $net, ?string $event, bool $dryRun): array
    {
        $aliasIds = app(CustomerMarketingHistory::class)->aliasIds($tenant, $profile->id);
        $touch = MarketingMessageDelivery::forTenantId($tenant)->whereIn('marketing_profile_id', $aliasIds)->whereNotNull('campaign_id')
            ->whereIn('send_status', ['sent', 'delivered'])->whereNotNull('sent_at')->where('sent_at', '<=', $order->closed_at)
            ->where('sent_at', '>=', $order->closed_at->copy()->subDays(7))->whereHas('campaign', fn ($q) => $q->where('tenant_id', $tenant))
            ->orderByDesc('sent_at')->orderByDesc('id')->first();
        $existing = MarketingCampaignConversion::where('source_type', 'square_order')->where('source_id', $order->square_order_id)
            ->whereHas('campaign', fn ($q) => $q->where('tenant_id', $tenant))->where('attribution_type', 'last_touch');
        $detail = ['campaign_id' => $touch?->campaign_id, 'delivery_id' => $touch?->id, 'event_record_id' => $event,
            'basis' => $touch ? ($touch->delivered_at || $touch->send_status === 'delivered' ? 'delivered_last_touch' : 'sent_only_modeled') : 'no_qualifying_text',
            'net_sales_minor' => $net, 'paid_total_minor' => (int) $order->total_money_amount, 'currency' => 'USD', 'causal_credit' => false];
        if (! $dryRun) {
            foreach ((clone $existing)->get() as $old) {
                if (! $touch || $old->campaign_id !== $touch->campaign_id) {
                    $snapshot = (array) $old->attribution_snapshot;
                    $snapshot['purchase_effects']['withdrawn'] = true;
                    $old->update(['attribution_type' => 'superseded', 'attribution_snapshot' => $snapshot]);
                }
            }
            if ($touch) {
                $conversion = (clone $existing)->where('campaign_id', $touch->campaign_id)->first() ?? new MarketingCampaignConversion;
                $snapshot = (array) $conversion->attribution_snapshot;
                $snapshot['purchase_effects'] = $detail;
                $values = ['campaign_id' => $touch->campaign_id, 'marketing_profile_id' => $profile->id,
                    'campaign_recipient_id' => $touch->campaign_recipient_id, 'attribution_type' => 'last_touch', 'source_type' => 'square_order',
                    'source_id' => $order->square_order_id, 'converted_at' => $order->closed_at, 'order_total' => $net === null ? null : $net / 100,
                    'notes' => 'Seven-day last text attribution; modeled correlation, not causal lift.', 'attribution_snapshot' => $snapshot];
                $conversion->fill($values);
                if ($conversion->isDirty()) {
                    $conversion->save();
                }
            }
        }

        return $detail;
    }

    private function payMirror(int $tenant, array $context): bool
    {
        if (! Schema::hasTable('pos_live_sales') || ! Schema::hasTable('payment_core_operations')) {
            return false;
        }

        return DB::table('pos_live_sales')->where('tenant_id', $tenant)->where(fn ($q) => $q->where('provider_order_id', $context['order']->square_order_id)
            ->orWhereIn('attempt_id', DB::table('payment_core_operations')->where('tenant_id', $tenant)->where('kind', 'payment')
                ->whereIn('provider_id', $context['payments']->pluck('square_payment_id'))->select('id')))->exists();
    }
}

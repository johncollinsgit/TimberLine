<?php

namespace App\Services\Marketing;

use App\Models\LandlordOperatorAction;
use App\Models\MarketingCampaign;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Content-free evidence history; original message/contact text stays in its existing campaign. */
class CustomerOutreachExplanation
{
    public function __construct(private CustomerOutreachPolicy $policy) {}

    public function build(MarketingProfile $profile, array $commerce, array $variables, array $decision, array $missingVariables = []): array
    {
        abort_unless((int) $profile->tenant_id === (int) ($decision['tenant_id'] ?? 0)
            && (int) $profile->id === (int) ($decision['profile_id'] ?? 0), 404);
        $behavior = $commerce['behavior'] ?? [];
        $issues = [];
        if (($commerce['has_more'] ?? false) || ($behavior['partial'] ?? true)) {
            $issues[] = 'bounded_or_partial_purchase_history';
        }
        if (! empty($commerce['issues'])) {
            $issues[] = 'source_or_identity_records_require_review';
        }
        if (($behavior['missing_item_purchase_count'] ?? 0) > 0) {
            $issues[] = 'purchase_item_details_missing';
        }
        if (($behavior['excluded_refunded_or_unknown_refund_purchases'] ?? 0) > 0) {
            $issues[] = 'refund_allocation_excluded_from_rankings';
        }
        if (($behavior['units_without_reviewed_scent'] ?? 0) > 0) {
            $issues[] = 'scent_mapping_missing_or_unreviewed';
        }
        if (($behavior['units_without_product_identity'] ?? 0) > 0) {
            $issues[] = 'stable_product_identity_missing';
        }
        if (($commerce['confirmed_purchase_count'] ?? 0) === 0) {
            $issues[] = 'no_confirmed_linked_purchases_observed';
        }
        if (! empty($missingVariables)) {
            $issues[] = 'required_personalization_value_missing';
        }
        // A purchase date is not a last-sync date. Missing observation evidence stays unknown.
        $issues[] = 'source_freshness_not_established';
        $freshness = $profile->updated_at ? ($profile->updated_at->lt(now()->subDays(180)) ? 'profile_record_stale' : 'profile_record_recent') : 'profile_record_freshness_unknown';
        if ($freshness !== 'profile_record_recent') {
            $issues[] = $freshness;
        }
        $purchases = array_map(fn ($purchase) => [
            'id' => (string) ($purchase['id'] ?? ''), 'source' => (string) ($purchase['source'] ?? ''),
            'source_id' => (string) ($purchase['source_id'] ?? ''), 'confirmed' => ($purchase['confirmed'] ?? false) === true,
            'occurred_at' => $purchase['occurred_at'] ?? null,
        ], $commerce['purchases'] ?? []);
        $usedVariables = array_values(array_unique(array_keys($variables)));
        sort($usedVariables);
        $missingVariables = array_values(array_unique($missingVariables));
        sort($missingVariables);

        return ['contract_version' => 1, 'tenant_id' => (int) $profile->tenant_id, 'profile_id' => (int) $profile->id,
            'prepared_at' => now()->toIso8601String(), 'why_prepared' => 'staff_selected_customer_and_authored_promotional_draft',
            'profile_record_updated_at' => $profile->updated_at?->toIso8601String(),
            'profile_record_freshness' => $freshness, 'personalization_variable_names' => $usedVariables,
            'personalization_evidence_hash' => $this->policy->hash($variables),
            'purchase_evidence' => $purchases, 'purchase_evidence_hash' => $this->policy->hash($purchases),
            'preparation_decision' => $decision,
            'quality' => ['issues' => array_values(array_unique($issues)), 'missing_variables' => $missingVariables,
                'coverage' => $commerce['coverage'] ?? 'unknown', 'has_more' => $commerce['has_more'] ?? null,
                'source_issue_count' => count($commerce['issues'] ?? []),
                'lifetime_history_complete' => false, 'lifetime_totals_supported' => false,
                'rankings_basis' => 'observed_explicitly_linked_confirmed_purchases',
                'source_freshness' => 'unknown', 'source_freshness_at' => null],
            'notice' => 'This records why a staff-authored draft was prepared. Observed purchase rankings are not declared preferences or complete lifetime history. Missing, stale and ambiguous evidence remains visible. No model, training, card data or provider calls are involved.'];
    }

    /** Called after campaign creation, in its tenant-locked transaction. Safe repeated calls reuse the original entry. */
    public function recordPreparation(MarketingCampaign $campaign, MarketingProfile $profile, array $explanation, ?int $actorId): int
    {
        $this->assertScope($campaign, $profile);
        abort_unless((int) ($explanation['tenant_id'] ?? 0) === (int) $campaign->tenant_id
            && (int) ($explanation['profile_id'] ?? 0) === (int) $profile->id, 422);
        $hash = $this->policy->hash($explanation);
        $contentHash = $this->contentHash($campaign);

        return DB::transaction(function () use ($campaign, $profile, $explanation, $actorId, $hash, $contentHash) {
            Tenant::whereKey($campaign->tenant_id)->lockForUpdate()->firstOrFail();
            $prior = $this->preparation($campaign);
            if ($prior) {
                abort_unless(($prior->context['explanation_hash'] ?? null) === $hash
                    && ($prior->context['content_hash'] ?? null) === $contentHash, 409, 'The preparation explanation and message are immutable.');

                return (int) $prior->id;
            }
            $entry = app(LandlordOperatorActionAuditService::class)->record((int) $campaign->tenant_id, $actorId,
                'customer_outreach_prepared', 'success', 'customer_outreach_campaign', $campaign->id,
                ['profile_id' => (int) $profile->id, 'channel' => $campaign->channel, 'explanation_hash' => $hash, 'content_hash' => $contentHash],
                [], null, null, ['explanation' => $explanation]);

            return (int) $entry->id;
        });
    }

    /** Read-only decision. Root records this inside the existing tenant-locked delivery claim. */
    public function dispatchDecision(MarketingCampaign $campaign, MarketingProfile $profile): array
    {
        $this->assertScope($campaign, $profile);
        $current = $this->policy->decision($profile, (string) $campaign->channel);
        $reasons = $current['reason_codes'];
        $entry = $this->preparation($campaign);
        $frozen = $campaign->target_snapshot['explanation'] ?? null;
        $preparationMatches = $entry && is_array($frozen) && (int) ($campaign->target_snapshot['preparation_audit_id'] ?? 0) === (int) $entry->id
            && hash_equals($entry->context['explanation_hash'] ?? '', $this->policy->hash($frozen));
        if (! $preparationMatches) {
            $reasons[] = 'outreach_preparation_evidence_missing_or_changed';
        } elseif (($frozen['quality']['missing_variables'] ?? []) !== []) {
            $reasons[] = 'outreach_personalization_evidence_missing';
        }
        if (! $entry || ! hash_equals($entry->context['content_hash'] ?? '', $this->contentHash($campaign))) {
            $reasons[] = 'outreach_original_content_changed_or_missing';
        }
        if ((int) ($current['profile_id'] ?? 0) !== (int) ($campaign->target_snapshot['profile_id'] ?? 0)) {
            $reasons[] = 'outreach_prepared_customer_identity_changed';
        }
        if (is_array($frozen) && ($frozen['preparation_decision']['preferences']['version'] ?? null) !== ($current['preferences']['version'] ?? null)) {
            $reasons[] = 'outreach_prepared_preferences_changed';
        }
        $rewardsRelated = in_array($campaign->objective, ['rewards', 'reward_reminder', 'reward_issuance'], true)
            || array_intersect($frozen['personalization_variable_names'] ?? [], ['rewards_name', 'rewards_balance']) !== [];
        if (($frozen['preparation_decision']['preferences']['rewards_communications'] ?? null) === false && $rewardsRelated) {
            $reasons[] = 'outreach_rewards_communications_declined';
        }
        if (($current['preferences']['rewards_communications'] ?? null) === false && $rewardsRelated) {
            $reasons[] = 'outreach_rewards_communications_declined';
        }
        // Seven-day-old prepared evidence needs a fresh authored/reviewed draft; don't rewrite it in place.
        if ($preparationMatches && CarbonImmutable::parse($frozen['prepared_at'])->lt(CarbonImmutable::now()->subDays(7))) {
            $reasons[] = 'outreach_preparation_stale';
        }
        $current['reason_codes'] = array_values(array_unique($reasons));
        $current['allowed'] = $current['reason_codes'] === [];
        $current['preparation_audit_id'] = $entry?->id;
        $current['preparation_policy_version'] = $frozen['preparation_decision']['policy']['version'] ?? null;

        return $current;
    }

    public function recordDispatchDecision(MarketingCampaign $campaign, MarketingProfile $profile, array $decision, ?int $actorId): int
    {
        $this->assertScope($campaign, $profile);
        abort_unless((int) ($decision['tenant_id'] ?? 0) === (int) $campaign->tenant_id, 422);
        $entry = app(LandlordOperatorActionAuditService::class)->record((int) $campaign->tenant_id, $actorId,
            'customer_outreach_dispatch_decision', $decision['allowed'] ? 'allowed' : 'blocked', 'customer_outreach_campaign', $campaign->id,
            ['profile_id' => (int) $profile->id, 'channel' => $campaign->channel, 'decision_hash' => $this->policy->hash($decision)],
            [], null, null, ['decision' => $decision]);

        return (int) $entry->id;
    }

    /** Requires an already authorized profile view; never exposed through a public receipt/rewards lookup. */
    public function history(MarketingProfile $profile, int $limit = 20): array
    {
        abort_unless((int) $profile->tenant_id > 0, 404);

        return LandlordOperatorAction::forTenantId($profile->tenant_id)
            ->whereIn('action_type', ['customer_outreach_prepared', 'customer_outreach_dispatch_decision'])
            ->where('context->profile_id', (int) $profile->id)->latest('id')->limit(max(1, min(100, $limit)))->get()
            ->map(fn ($row) => ['id' => (int) $row->id, 'action' => $row->action_type, 'status' => $row->status,
                'campaign_id' => (int) $row->target_id, 'created_at' => $row->created_at?->toIso8601String(),
                'explanation' => $row->result['explanation'] ?? null, 'decision' => $row->result['decision'] ?? null])->all();
    }

    private function preparation(MarketingCampaign $campaign): ?LandlordOperatorAction
    {
        return LandlordOperatorAction::forTenantId($campaign->tenant_id)->where('action_type', 'customer_outreach_prepared')
            ->where('target_type', 'customer_outreach_campaign')->where('target_id', (string) $campaign->id)->first();
    }

    private function contentHash(MarketingCampaign $campaign): string
    {
        return $this->policy->hash(['channel' => $campaign->channel, 'subject' => $campaign->message_subject,
            'body' => $campaign->message_body, 'html' => $campaign->message_html]);
    }

    private function assertScope(MarketingCampaign $campaign, MarketingProfile $profile): void
    {
        abort_unless($campaign->source_label === 'customer_profile' && in_array($campaign->channel, ['email', 'sms'], true)
            && (int) $profile->tenant_id > 0 && (int) $campaign->tenant_id === (int) $profile->tenant_id
            && (int) ($campaign->target_snapshot['profile_id'] ?? 0) === (int) $profile->id, 404);
    }
}

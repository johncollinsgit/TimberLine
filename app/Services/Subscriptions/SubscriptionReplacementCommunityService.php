<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionMemberVote;
use App\Models\SubscriptionPilotMember;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Member benefits follow reviewed Everbranch membership, independently of its payment processor or old mirror status. */
class SubscriptionReplacementCommunityService
{
    public function membership(ModernForestryMobileCustomerSession $session, bool $activeOnly = false): ?SubscriptionBillingAgreement
    {
        $row = app(SubscriptionReplacementCustomerAccess::class)->agreement($session);
        if (! $row || ! app(SubscriptionAccessService::class)->entitled($row->tenant_id)) {
            return null;
        }
        if (app(SubscriptionMembershipReviewService::class)->program($row) !== 'candle_club'
            || ($activeOnly && $row->status !== 'active')) {
            return null;
        }

        return $row;
    }

    public function lockedMembership(ModernForestryMobileCustomerSession $session): ?SubscriptionBillingAgreement
    {
        $scope = $this->membership($session, true);
        if (! $scope) {
            return null;
        }
        $member = SubscriptionPilotMember::query()->forTenantId($scope->tenant_id)->whereKey($scope->member_id)->lockForUpdate()->firstOrFail();
        $current = SubscriptionBillingAgreement::query()->forTenantId($scope->tenant_id)->whereKey($scope->id)->lockForUpdate()->firstOrFail();
        // Use the locking reads: a repeatable-read snapshot may still contain the pre-pause state.
        if ($current->status !== 'active' || ! $current->activated_at || ! $current->method_verified_at
            || $member->billing_owner !== 'everbranch' || $current->member_id !== $member->id) {
            return null;
        }
        SubscriptionBillingAuthorization::original($current);

        return $current;
    }

    public function payload(ModernForestryMobileCustomerSession $session): array
    {
        $row = $this->membership($session);
        if (! $row) {
            return ['eligible' => false, 'active_poll' => null, 'vote_history' => [], 'monthly_scents' => [], 'previous_chosen_scents' => [], 'can_vote' => false];
        }
        $content = app(SubscriptionModuleService::class)->candleClubContent($row->tenant_id);
        $history = $this->history($row);
        $poll = $content['active_poll'];
        if ($poll) {
            $poll['already_voted'] = collect($history)->contains('poll_id', $poll['id']);
        }

        return array_replace($content, ['active_poll' => $poll, 'eligible' => $row->status === 'active', 'vote_history' => $history,
            'can_vote' => $row->status === 'active' && $poll !== null && ! $poll['already_voted']
                && Schema::hasTable('subscription_member_votes') && ! $this->ambiguousVote($row, $poll['id'])
                && app(SubscriptionReplacementCustomerAccess::class)->canChange($row, 'resume')]);
    }

    public function vote(ModernForestryMobileCustomerSession $session, array $input): array
    {
        abort_unless(Schema::hasTable('subscription_member_votes'), 423, 'Member voting is not ready yet.');
        $data = Validator::make($input, ['poll_id' => ['required', 'integer', 'min:1'], 'option_id' => ['required', 'integer', 'min:1'],
            'request_id' => ['nullable', 'uuid']])->validate();
        $result = DB::transaction(function () use ($session, $data) {
            $row = $this->lockedMembership($session);
            if (! $row || ! app(SubscriptionReplacementCustomerAccess::class)->canChange($row, 'resume')) {
                return ['ok' => false, 'status' => 'not_eligible', 'message' => 'Voting is available for active Candle Club members.'];
            }
            $poll = app(SubscriptionModuleService::class)->lockOpenCandleClubPoll($row->tenant_id);
            if (! $poll || $poll['id'] !== (int) $data['poll_id']) {
                return ['ok' => false, 'status' => 'poll_not_open', 'message' => 'Voting is not open for that Candle Club poll.'];
            }
            $option = DB::table('subscription_poll_options')->where('tenant_id', $row->tenant_id)->where('subscription_poll_id', $poll['id'])
                ->where('id', $data['option_id'])->lockForUpdate()->first();
            if (! $option) {
                return ['ok' => false, 'status' => 'invalid_vote_option', 'message' => 'Choose one of the current Candle Club voting options.'];
            }
            if (collect($this->history($row, true, $poll['id']))->isNotEmpty()) {
                return ['ok' => false, 'status' => 'already_voted', 'message' => 'Your Candle Club vote has already been recorded.'];
            }
            if ($this->ambiguousVote($row, $poll['id'])) {
                return ['ok' => false, 'status' => 'membership_review_required', 'message' => 'A previous vote needs our team to verify its original membership before another vote can be recorded.'];
            }
            $request = isset($data['request_id']) ? strtolower($data['request_id']) : (string) Str::uuid();
            if (SubscriptionMemberVote::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)->where('request_uuid', $request)->lockForUpdate()->first()) {
                return ['ok' => false, 'status' => 'request_conflict', 'message' => 'This request ID belongs to an earlier vote.'];
            }
            SubscriptionMemberVote::create(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'member_id' => $row->member_id,
                'subscription_poll_id' => $poll['id'], 'subscription_poll_option_id' => $option->id, 'marketing_profile_id' => $session->profile->id,
                'request_uuid' => $request, 'agreement_revision' => $row->revision, 'evidence' => [
                    'actor_reference' => 'gid://shopify/Customer/'.$row->terms['shopify_customer_id'],
                    'original_terms_hash' => SubscriptionBillingAuthorization::original($row)['terms_hash'],
                    'shopify_store_id' => $row->terms['shopify_store_id'], 'shop_domain' => $row->terms['shop_domain'],
                    'option_label' => $option->label, 'source' => 'authenticated_customer',
                ]]);

            return ['ok' => true, 'status' => 'vote_recorded', 'message' => 'Your Candle Club vote has been recorded.'];
        });

        return $result + ['action' => 'vote', 'candle_club' => app(SubscriptionReplacementCustomerAccess::class)->payload($session, [])];
    }

    /** An exact retained source identity links old votes, without changing the old contract or trusting email/profile merges. */
    public function legacyContractIds(SubscriptionBillingAgreement $row): array
    {
        $terms = SubscriptionBillingAuthorization::original($row)['terms'];

        return DB::table('subscription_contracts')->where('tenant_id', $row->tenant_id)->where('is_candle_club', true)
            ->where('shopify_customer_gid', 'gid://shopify/Customer/'.$terms['shopify_customer_id'])
            ->whereIn('recharge_subscription_id', array_column($terms['lines'], 'subscription_id'))
            ->where('shopify_subscription_contract_gid', 'like', 'gid://shopify/SubscriptionContract/%')
            ->where(fn ($q) => $q->whereNull('metadata->app_review_demo')->orWhere('metadata->app_review_demo', false))->pluck('id')->all();
    }

    private function ambiguousVote(SubscriptionBillingAgreement $row, int $pollId): bool
    {
        return DB::table('subscription_votes as v')->join('subscription_contracts as c', 'c.id', '=', 'v.subscription_contract_id')
            ->where('v.tenant_id', $row->tenant_id)->where('c.tenant_id', $row->tenant_id)->where('v.subscription_poll_id', $pollId)
            ->where('c.is_candle_club', true)->where('c.shopify_customer_gid', 'gid://shopify/Customer/'.$row->terms['shopify_customer_id'])
            ->whereNull('c.recharge_subscription_id')->whereColumn('v.shopify_subscription_contract_gid', 'c.shopify_subscription_contract_gid')->exists();
    }

    public function convertedContract(int $tenantId, object $contract, bool $currentRead = false): bool
    {
        if (! Schema::hasTable('subscription_billing_agreements') || ! preg_match('#\Agid://shopify/Customer/([1-9][0-9]*)\z#', (string) ($contract->shopify_customer_gid ?? ''), $match)) {
            return false;
        }
        $members = SubscriptionPilotMember::query()->forTenantId($tenantId)->where('shopify_customer_id', $match[1])
            ->when($currentRead, fn ($query) => $query->lockForUpdate())->pluck('id');
        foreach (SubscriptionBillingAgreement::query()->forTenantId($tenantId)->whereIn('member_id', $members)->whereNotNull('activated_at')
            ->when($currentRead, fn ($query) => $query->lockForUpdate())->get() as $row) {
            if (in_array((string) ($contract->recharge_subscription_id ?? ''), array_column(SubscriptionBillingAuthorization::original($row)['terms']['lines'], 'subscription_id'), true)
                || data_get($row->authorization_evidence, 'shopify_adoption.contract_id') === ($contract->shopify_subscription_contract_gid ?? null)) {
                return true;
            }
        }

        return false;
    }

    public function history(SubscriptionBillingAgreement $row, bool $currentRead = false, ?int $pollId = null): array
    {
        $legacy = DB::table('subscription_votes as v')->join('subscription_contracts as c', 'c.id', '=', 'v.subscription_contract_id')
            ->join('subscription_polls as p', 'p.id', '=', 'v.subscription_poll_id')->join('subscription_poll_options as o', 'o.id', '=', 'v.subscription_poll_option_id')
            ->where('v.tenant_id', $row->tenant_id)->where('c.tenant_id', $row->tenant_id)->where('p.tenant_id', $row->tenant_id)->where('o.tenant_id', $row->tenant_id)
            ->whereColumn('v.shopify_subscription_contract_gid', 'c.shopify_subscription_contract_gid')->whereColumn('o.subscription_poll_id', 'p.id')
            ->whereIn('c.id', $this->legacyContractIds($row))->when($pollId, fn ($q) => $q->where('v.subscription_poll_id', $pollId))
            ->select(['p.id as poll_id', 'p.title as poll_title', 'o.label as option_label', 'v.created_at as voted_at'])
            ->when($currentRead, fn ($q) => $q->lockForUpdate())->get();
        $current = Schema::hasTable('subscription_member_votes') ? $this->votes($row->tenant_id)->where('v.member_id', $row->member_id)
            ->join('subscription_polls as p', 'p.id', '=', 'v.subscription_poll_id')->join('subscription_poll_options as o', 'o.id', '=', 'v.subscription_poll_option_id')
            ->where('p.tenant_id', $row->tenant_id)->where('o.tenant_id', $row->tenant_id)->whereColumn('o.subscription_poll_id', 'p.id')
            ->when($pollId, fn ($q) => $q->where('v.subscription_poll_id', $pollId))
            ->select(['p.id as poll_id', 'p.title as poll_title', 'o.label as option_label', 'v.created_at as voted_at'])
            ->when($currentRead, fn ($q) => $q->lockForUpdate())->get() : collect();

        return $legacy->merge($current)->sortByDesc('voted_at')->unique('poll_id')->map(fn ($v) => ['poll_id' => (int) $v->poll_id,
            'poll_title' => $v->poll_title, 'option_label' => $v->option_label, 'voted_at' => $v->voted_at])->values()->all();
    }

    public function votes(int $tenantId)
    {
        return DB::table('subscription_member_votes as v')->join('subscription_billing_agreements as a', function ($join) {
            $join->on('a.id', '=', 'v.agreement_id')->on('a.tenant_id', '=', 'v.tenant_id')->on('a.member_id', '=', 'v.member_id');
        })->join('subscription_pilot_members as m', function ($join) {
            $join->on('m.id', '=', 'v.member_id')->on('m.tenant_id', '=', 'v.tenant_id');
        })->where('v.tenant_id', $tenantId);
    }
}

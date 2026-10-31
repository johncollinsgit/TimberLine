<?php

namespace App\Services\Subscriptions;

use App\Models\ShopifyStore;
use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionBillingCycle;
use App\Models\SubscriptionPilotMember;
use App\Models\User;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Accepts reviewed future Braintree terms; no source stop, order mutation or collection. */
class SubscriptionBillingAmendmentService
{
    public function apply(int $tenantId, User $actor, int $agreementId, array $input, array $approval,
        string $quoteHash, string $uuid, int $revision): SubscriptionBillingAction
    {
        $scope = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);
        $this->authorize($scope, $actor);
        $input = app(SubscriptionAmendmentProposal::class)->normalize($input);
        $approval = Validator::make(['approval' => $approval], ['approval' => ['required', 'array:reference,quoted_total_reviewed,continuing_authorization_reviewed'],
            'approval.reference' => ['required', 'string', 'regex:/\A[A-Za-z0-9_.:\/-]{1,190}\z/'],
            'approval.quoted_total_reviewed' => ['required', 'accepted'], 'approval.continuing_authorization_reviewed' => ['required', 'accepted']])->validate()['approval'];
        $approval['quoted_total_reviewed'] = $approval['continuing_authorization_reviewed'] = true;
        abort_unless(Str::isUuid($uuid) && $revision >= 1 && preg_match('/\A[a-f0-9]{64}\z/', $quoteHash), 422, 'Provide the exact reviewed quote, revision and request ID.');
        $uuid = strtolower($uuid);
        if (SubscriptionBillingAction::query()->forTenantId($tenantId)->where('agreement_id', $scope->id)->where('request_uuid', $uuid)->exists()) {
            return $this->save($scope, $actor, $input, $approval, $quoteHash, $uuid, $revision, null);
        }
        $this->assertFuture($scope);
        try {
            $quote = app(SubscriptionAmendmentQuote::class)->calculate($scope, $input, $actor);
        } catch (\Throwable $error) {
            if (SubscriptionBillingAction::query()->forTenantId($tenantId)->where('agreement_id', $scope->id)->where('request_uuid', $uuid)->exists()) {
                return $this->save($scope, $actor, $input, $approval, $quoteHash, $uuid, $revision, null);
            }
            throw $error;
        }
        abort_unless(hash_equals($quote['quote_hash'], $quoteHash), 409, 'The calculated products, shipping, tax or original context changed. Review the new quote.');

        return $this->save($scope, $actor, $input, $approval, $quoteHash, $uuid, $revision, $quote);
    }

    public function customerReplay(ModernForestryMobileCustomerSession $session, string $uuid, int $revision, string $hash): ?SubscriptionBillingAction
    {
        abort_unless(Str::isUuid($uuid) && $revision >= 1 && preg_match('/\A[a-f0-9]{64}\z/', $hash), 422);
        $scope = app(SubscriptionReplacementCustomerAccess::class)->agreement($session);
        abort_unless($scope, 404);

        return DB::transaction(function () use ($scope, $session, $uuid, $revision, $hash) {
            SubscriptionPilotMember::query()->forTenantId($scope->tenant_id)->whereKey($scope->member_id)->lockForUpdate()->firstOrFail();
            $row = SubscriptionBillingAgreement::query()->forTenantId($scope->tenant_id)->whereKey($scope->id)->lockForUpdate()->firstOrFail();
            app(SubscriptionCustomerAmendmentService::class)->assertIdentity($row, $session, true);
            SubscriptionBillingAuthorization::assertCurrent($row, true);
            $prior = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)->where('request_uuid', strtolower($uuid))->lockForUpdate()->first();
            if ($prior) {
                abort_unless($prior->kind === 'terms_amended' && $prior->actor_type === 'shopify_customer'
                    && $prior->actor_reference === app(SubscriptionCustomerAmendmentService::class)->reference($session)
                    && $prior->expected_revision === $revision && data_get($prior->request, 'customer_review.profile_id') === $session->profile->id
                    && data_get($prior->request, 'customer_review.review_hash') === $hash, 409, 'This request ID belongs to another accepted action.');
            }

            return $prior;
        });
    }

    public function acceptCustomer(SubscriptionBillingAgreement $row, ModernForestryMobileCustomerSession $session,
        SubscriptionBillingAction $policy, array $review, array $quote, bool $confirmed): SubscriptionBillingAction
    {
        abort_unless($confirmed, 422, 'Confirm your reviewed future delivery and total.');
        $e = $review['evidence'];
        abort_unless(($quote['quote_hash'] ?? null) === $e['quote_hash'], 409, 'Review the current delivery total.');

        return $this->save($row, User::findOrFail((int) $policy->actor_reference), $review['input'],
            ['reference' => 'delivery-policy:'.$policy->request_uuid, 'quoted_total_reviewed' => true, 'continuing_authorization_reviewed' => true],
            $quote['quote_hash'], $e['request_uuid'], $e['revision'], $quote, ['session' => $session, 'review' => $review]);
    }

    private function save(SubscriptionBillingAgreement $scope, User $actor, array $input, array $approval, string $quoteHash,
        string $uuid, int $revision, ?array $quote, ?array $customer = null): SubscriptionBillingAction
    {
        return DB::transaction(function () use ($scope, $actor, $input, $approval, $quoteHash, $uuid, $revision, $quote, $customer) {
            SubscriptionPilotMember::query()->forTenantId($scope->tenant_id)->whereKey($scope->member_id)->lockForUpdate()->firstOrFail();
            $row = SubscriptionBillingAgreement::query()->forTenantId($scope->tenant_id)->whereKey($scope->id)->lockForUpdate()->firstOrFail();
            if ($customer) {
                app(SubscriptionCustomerAmendmentService::class)->assertIdentity($row, $customer['session'], true);
            } else {
                $this->authorize($row, $actor, true);
            }
            SubscriptionBillingAuthorization::assertCurrent($row, true);
            $actorType = $customer ? 'shopify_customer' : 'user';
            $actorReference = $customer ? app(SubscriptionCustomerAmendmentService::class)->reference($customer['session']) : (string) $actor->id;
            $prior = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)->where('request_uuid', $uuid)->lockForUpdate()->first();
            if ($prior) {
                abort_unless($prior->kind === 'terms_amended' && $prior->actor_type === $actorType && $prior->actor_reference === $actorReference
                    && $prior->expected_revision === $revision && ($prior->request['quote']['quote_hash'] ?? null) === $quoteHash
                    && SubscriptionEvidence::canonical($prior->request['input'] ?? []) === SubscriptionEvidence::canonical($input)
                    && SubscriptionEvidence::canonical($prior->request['approval'] ?? []) === SubscriptionEvidence::canonical($approval)
                    && (! $customer || SubscriptionEvidence::canonical($prior->request['customer_review'] ?? []) === SubscriptionEvidence::canonical($customer['review']['evidence'])),
                    409, 'This request ID belongs to another accepted action.');

                return $prior;
            }
            if ($customer) {
                $service = app(SubscriptionCustomerAmendmentService::class);
                $policy = $service->policy($row, $customer['session'], true);
                $service->assertReview($row, $customer['session'], $customer['review']);
                $service->assertPolicy($row, $policy, $customer['review']);
                abort_unless((int) $policy->actor_reference === $actor->id, 409, 'The merchant review changed.');
            }
            abort_unless($quote && $row->revision === $revision && $row->terms_hash === $scope->terms_hash
                && ($quote['subject']['revision'] ?? null) === $revision && ($quote['subject']['current_terms_hash'] ?? null) === $row->terms_hash
                && ($quote['subject']['agreement_id'] ?? null) === $row->id && ($quote['subject']['member_id'] ?? null) === $row->member_id
                && CarbonImmutable::parse($quote['checked_at'])->betweenIncluded(now()->subMinutes(2), now()->addSeconds(5)),
                409, 'The original agreement or quote changed before acceptance.');
            $this->assertFuture($row, true);
            $store = ShopifyStore::query()->forTenantId($row->tenant_id)->findOrFail($row->terms['shopify_store_id']);
            abort_unless($store->shop_domain === $quote['context']['shop_domain'] && $store->id === $quote['context']['shopify_store_id']
                && hash_equals(SubscriptionEvidence::digest((string) $store->access_token), $quote['context']['credential_fingerprint']),
                409, 'The original quote credential changed before acceptance.');
            $original = SubscriptionBillingAuthorization::original($row);
            SubscriptionBillingTermsHistory::allowed($original['terms'], $quote['terms']);
            $hash = SubscriptionBillingTermsHistory::termsHash($row, $quote['terms'], $uuid);
            $action = new SubscriptionBillingAction(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'request_uuid' => $uuid,
                'kind' => 'terms_amended', 'actor_type' => $actorType, 'actor_reference' => $actorReference, 'expected_revision' => $revision,
                'request' => ['version' => 1, 'original_terms_hash' => $original['terms_hash'], 'agreement_public_id' => $row->public_id,
                    'member_id' => $row->member_id, 'accepted_by' => $customer ? $actorReference : $actor->id, 'input' => $input, 'approval' => $approval, 'quote' => $quote]
                    + ($customer ? ['customer_review' => $customer['review']['evidence'], 'continuing_authorization_reviewed_by' => $actor->id] : []),
                'before_state' => ['terms' => $row->terms, 'terms_hash' => $row->terms_hash],
                'after_state' => ['terms' => $quote['terms'], 'terms_hash' => $hash, 'revision' => $revision + 1,
                    'effective_from' => $row->status === 'paused' ? null : $row->next_billing_date->toDateString(),
                    'effective_after_resume' => $row->status === 'paused']]);
            $after = $action->after_state;
            $after['authorization_hash'] = SubscriptionBillingTermsHistory::seal($action);
            $action->after_state = $after;
            $action->save();
            $row->update(['terms' => $quote['terms'], 'terms_hash' => $hash, 'revision' => $revision + 1]);
            SubscriptionBillingAuthorization::assertCurrent($row->fresh(), true);

            return $action;
        });
    }

    public function authorize(SubscriptionBillingAgreement $row, User $actor, bool $currentRead = false): void
    {
        $actor = $currentRead ? User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail() : $actor->fresh();
        if ($currentRead) {
            $membership = DB::table('tenant_user')->where('tenant_id', $row->tenant_id)->where('user_id', $actor->id)->lockForUpdate()->first();
            abort_unless($actor->is_active && $actor->email_verified_at && $membership && $membership->membership_active
                && in_array(strtolower($membership->role), ['owner', 'tenant_owner', 'admin'], true), 403, 'The current verified owner/admin permission is required.');
        }
        app(SubscriptionReplacementAccess::class)->owner($row->tenant_id, $actor, $row->member_id, 'servicing');
        $member = app(SubscriptionReplacementAccess::class)->member($row);
        abort_unless(config('subscriptions.replacement_amendments_enabled') === true && config('subscriptions.replacement_amendment_apply_enabled') === true,
            423, 'Reviewed future terms are not enabled for this membership.');
        abort_unless($row->processor === 'braintree' && $row->activated_at && $row->method_verified_at && $member->billing_owner === 'everbranch',
            409, 'Review the individually activated Braintree membership first.');
        SubscriptionBillingAuthorization::original($row);
    }

    public function assertFuture(SubscriptionBillingAgreement $row, bool $currentRead = false): void
    {
        $clock = now($row->terms['timezone']);
        abort_unless(in_array($row->status, ['active', 'paused'], true) && $row->next_billing_date
            && ($row->status === 'paused' || $row->next_billing_date->toDateString() > $clock->toDateString()
                || ($row->next_billing_date->toDateString() === $clock->toDateString() && $clock->hour < $row->terms['billing_hour'])),
            409, 'Only a future unstarted cycle or a paused membership can accept new delivery terms.');
        abort_if(SubscriptionBillingCycle::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)
            ->where(fn ($q) => $q->whereIn('payment_status', ['prepared', 'sending', 'unknown', 'processing', 'requires_action', 'review', 'refunded', 'disputed'])
                ->orWhere(fn ($q) => $q->where('payment_status', 'succeeded')->where('handoff_status', '!=', 'confirmed')))
            ->when($currentRead, fn ($q) => $q->lockForUpdate())->first(), 409, 'An original payment or Shopify handoff needs reconciliation before terms change.');
    }
}

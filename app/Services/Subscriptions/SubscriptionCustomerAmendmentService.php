<?php

namespace App\Services\Subscriptions;

use App\Models\MarketingProfile;
use App\Models\ShopifyStore;
use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionPilotMember;
use App\Models\User;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Private customer quote/confirmation; original saved payment authority remains separate. */
class SubscriptionCustomerAmendmentService
{
    public function options(ModernForestryMobileCustomerSession $session): ?array
    {
        $row = app(SubscriptionReplacementCustomerAccess::class)->agreement($session);
        if (! $row || ! config('subscriptions.replacement_customer_delivery_enabled')) {
            return null;
        }
        try {
            $policy = $this->policy($row, $session);
            app(SubscriptionBillingAmendmentService::class)->assertFuture($row);

            return ['provinces' => $policy->request['policy']['provinces'],
                'scents' => $policy->request['policy']['allow_scent_swaps'] ? $policy->request['policy']['scents'] : []];
        } catch (HttpException|\Illuminate\Validation\ValidationException|\Illuminate\Database\Eloquent\ModelNotFoundException $error) {
            if ($error instanceof HttpException && ! in_array($error->getStatusCode(), [403, 409, 423], true)) {
                throw $error;
            }

            return null;
        }
    }

    public function review(ModernForestryMobileCustomerSession $session, array $change, string $uuid, int $revision): array
    {
        abort_unless(Str::isUuid($uuid) && $revision >= 1, 422, 'Start a fresh delivery review.');
        $uuid = strtolower($uuid);
        $row = app(SubscriptionReplacementCustomerAccess::class)->agreement($session);
        abort_unless($row, 404);
        $policy = $this->policy($row, $session);
        abort_unless($row->revision === $revision, 409, 'Your subscription changed. Review its current delivery first.');
        app(SubscriptionBillingAmendmentService::class)->assertFuture($row);
        Validator::make(['change' => $change], ['change' => ['required', 'array:shipping_address,swaps'], 'change.shipping_address' => ['required', 'array']])->validate();
        $input = app(SubscriptionAmendmentProposal::class)->normalize($change + ['fixed_shipping_reviewed' => true, 'policy_reference' => 'delivery-policy:'.$policy->request_uuid]);
        app(SubscriptionDeliveryPolicyService::class)->permits($row, $policy, $input);
        $key = $this->key($row, $session, $uuid);
        $existing = $this->cached($key);
        if (is_array($existing)) {
            $this->assertReview($row, $session, $existing);
            abort_unless(SubscriptionEvidence::canonical($existing['input']) === SubscriptionEvidence::canonical($input)
                && $existing['evidence']['policy_action_id'] === $policy->id, 409, 'This review ID already belongs to another delivery choice. Start a new review.');

            return $existing;
        }
        // Provider calculation runs under the explicitly recorded merchant reviewer,
        // while confirmation and the accepted action retain the real customer actor.
        $quote = app(SubscriptionAmendmentQuote::class)->calculate($row, $input, User::findOrFail((int) $policy->actor_reference));
        $currentPolicy = $this->policy($row->fresh(), $session);
        abort_unless($currentPolicy->id === $policy->id, 409, 'Our delivery options changed. Start a new review.');
        $evidence = ['version' => 1, 'request_uuid' => $uuid, 'revision' => $revision, 'customer_gid' => $this->reference($session),
            'profile_id' => $session->profile->id, 'valid_until' => now()->addMinutes(10)->timestamp,
            'policy_action_id' => $policy->id, 'policy_uuid' => $policy->request_uuid, 'policy_hash' => $policy->request['reviewed_hash'],
            'quote_hash' => $quote['quote_hash'], 'input_hash' => SubscriptionEvidence::digest(SubscriptionEvidence::canonical($input)),
            'quoted_terms_hash' => SubscriptionEvidence::digest(SubscriptionEvidence::canonical($quote['terms'])),
            'confirmed_total_cents' => $quote['terms']['amount_cents']];
        $evidence['review_hash'] = $this->seal($evidence);
        $review = ['evidence' => $evidence, 'input' => $input, 'quote' => $quote];
        if (! Cache::add($key, Crypt::encryptString(json_encode($review, JSON_THROW_ON_ERROR)), now()->addMinutes(10))) {
            $existing = $this->cached($key);
            abort_unless(is_array($existing), 409, 'Start a new delivery review.');
            $this->assertReview($row->fresh(), $session, $existing);
            abort_unless(SubscriptionEvidence::canonical($existing['input']) === SubscriptionEvidence::canonical($input)
                && $existing['evidence']['quote_hash'] === $evidence['quote_hash'] && $existing['evidence']['policy_action_id'] === $policy->id,
                409, 'This review ID already belongs to another delivery choice.');

            return $existing;
        }

        return $review;
    }

    public function apply(ModernForestryMobileCustomerSession $session, string $uuid, int $revision, string $hash, bool $confirmed): SubscriptionBillingAction
    {
        abort_unless($confirmed && Str::isUuid($uuid) && $revision >= 1 && preg_match('/\A[a-f0-9]{64}\z/', $hash), 422, 'Confirm the reviewed future delivery and total.');
        $uuid = strtolower($uuid);
        $billing = app(SubscriptionBillingAmendmentService::class);
        if ($prior = $billing->customerReplay($session, $uuid, $revision, $hash)) {
            return $prior;
        }
        $row = app(SubscriptionReplacementCustomerAccess::class)->agreement($session);
        abort_unless($row, 404);
        $review = $this->cached($this->key($row, $session, $uuid));
        abort_unless(is_array($review), 409, 'Your delivery review expired. Review the delivery and total again.');
        $this->assertReview($row, $session, $review);
        abort_unless(hash_equals($review['evidence']['review_hash'], $hash) && $revision === $review['evidence']['revision']
            && $uuid === $review['evidence']['request_uuid'], 409, 'Confirm your exact reviewed delivery and total.');
        $policy = $this->policy($row, $session);
        $this->assertPolicy($row, $policy, $review);
        try {
            $quote = app(SubscriptionAmendmentQuote::class)->calculate($row, $review['input'], User::findOrFail((int) $policy->actor_reference));
            abort_unless(hash_equals($quote['quote_hash'], $review['evidence']['quote_hash']), 409, 'The delivery, availability or tax total changed. Review the new total before saving.');

            return $billing->acceptCustomer($row, $session, $policy, $review, $quote, true);
        } catch (\Throwable $error) {
            if ($prior = $billing->customerReplay($session, $uuid, $revision, $hash)) {
                return $prior;
            }
            throw $error;
        }
    }

    public function policy(SubscriptionBillingAgreement $row, ModernForestryMobileCustomerSession $session, bool $currentRead = false): SubscriptionBillingAction
    {
        $this->assertIdentity($row, $session, $currentRead);
        abort_unless(config('subscriptions.replacement_customer_delivery_enabled') === true, 423, 'Delivery changes are temporarily unavailable.');
        $policy = app(SubscriptionDeliveryPolicyService::class)->latest($row, $currentRead);
        abort_unless($policy && $policy->request['policy']['enabled'], 423, 'Our team needs to review delivery options for this membership.');
        app(SubscriptionBillingAmendmentService::class)->authorize($row, User::findOrFail((int) $policy->actor_reference), $currentRead);
        SubscriptionBillingAuthorization::assertCurrent($row, $currentRead);

        return $policy;
    }

    public function assertIdentity(SubscriptionBillingAgreement $row, ModernForestryMobileCustomerSession $session, bool $currentRead = false): void
    {
        $profile = MarketingProfile::query()->where('tenant_id', $row->tenant_id)->whereKey($session->profile->id)
            ->when($currentRead, fn ($q) => $q->lockForUpdate())->first();
        $store = ShopifyStore::query()->forTenantId($row->tenant_id)->whereKey($row->terms['shopify_store_id'])
            ->when($currentRead, fn ($q) => $q->lockForUpdate())->first();
        $member = SubscriptionPilotMember::query()->forTenantId($row->tenant_id)->whereKey($row->member_id)
            ->when($currentRead, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($profile && $session->profile->tenant_id === $row->tenant_id && $store && $store->store_key === 'retail'
            && $store->shop_domain === $row->terms['shop_domain'] && $member && $member->billing_owner === 'everbranch'
            && $this->reference($session) === 'gid://shopify/Customer/'.$row->terms['shopify_customer_id']
            && $row->activated_at && $row->method_verified_at, 404);
        app(SubscriptionReplacementAccess::class)->member($row);
    }

    public function assertReview(SubscriptionBillingAgreement $row, ModernForestryMobileCustomerSession $session, array $review): void
    {
        $e = $review['evidence'] ?? [];
        abort_unless(($e['version'] ?? null) === 1 && ($e['customer_gid'] ?? null) === $this->reference($session) && ($e['profile_id'] ?? null) === $session->profile->id
            && ($e['revision'] ?? null) === $row->revision && is_int($e['valid_until'] ?? null) && $e['valid_until'] > now()->timestamp
            && is_string($e['review_hash'] ?? null) && hash_equals($this->seal($e), $e['review_hash'])
            && ($e['quote_hash'] ?? null) === data_get($review, 'quote.quote_hash')
            && ($e['confirmed_total_cents'] ?? null) === data_get($review, 'quote.terms.amount_cents')
            && ($e['quoted_terms_hash'] ?? null) === SubscriptionEvidence::digest(SubscriptionEvidence::canonical($review['quote']['terms'] ?? []))
            && ($e['input_hash'] ?? null) === SubscriptionEvidence::digest(SubscriptionEvidence::canonical($review['input'] ?? []))
            && data_get($review, 'quote.subject.agreement_id') === $row->id && data_get($review, 'quote.subject.tenant_id') === $row->tenant_id
            && data_get($review, 'quote.subject.current_terms_hash') === $row->terms_hash, 409, 'Your reviewed delivery or subscription changed. Review it again.');
    }

    public function assertPolicy(SubscriptionBillingAgreement $row, SubscriptionBillingAction $policy, array $review): void
    {
        abort_unless($policy->id === $review['evidence']['policy_action_id'] && $policy->request_uuid === $review['evidence']['policy_uuid']
            && $policy->request['reviewed_hash'] === $review['evidence']['policy_hash'], 409, 'Our delivery options changed. Start a new review.');
        app(SubscriptionDeliveryPolicyService::class)->permits($row, $policy, $review['input']);
    }

    public function assertHistory(SubscriptionBillingAgreement $row, SubscriptionBillingAction $action, bool $currentRead): void
    {
        $r = $action->request;
        $e = $r['customer_review'] ?? [];
        $policy = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)
            ->whereKey($e['policy_action_id'] ?? 0)->when($currentRead, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($policy && $action->actor_reference === 'gid://shopify/Customer/'.SubscriptionBillingAuthorization::original($row)['terms']['shopify_customer_id']
            && ($r['accepted_by'] ?? null) === $action->actor_reference && ($e['version'] ?? null) === 1
            && ($e['customer_gid'] ?? null) === $action->actor_reference && is_int($e['profile_id'] ?? null) && $e['profile_id'] > 0
            && ($e['request_uuid'] ?? null) === $action->request_uuid && ($e['revision'] ?? null) === $action->expected_revision
            && is_int($e['valid_until'] ?? null) && $e['valid_until'] > 0
            && ($e['quote_hash'] ?? null) === data_get($r, 'quote.quote_hash')
            && ($e['confirmed_total_cents'] ?? null) === data_get($action->after_state, 'terms.amount_cents')
            && ($e['quoted_terms_hash'] ?? null) === SubscriptionEvidence::digest(SubscriptionEvidence::canonical($action->after_state['terms'] ?? []))
            && ($e['input_hash'] ?? null) === SubscriptionEvidence::digest(SubscriptionEvidence::canonical($r['input'] ?? []))
            && is_string($e['review_hash'] ?? null) && hash_equals($this->seal($e), $e['review_hash'])
            && ($r['approval']['reference'] ?? null) === 'delivery-policy:'.$policy->request_uuid
            && ($r['continuing_authorization_reviewed_by'] ?? null) === (int) $policy->actor_reference,
            409, 'The retained customer delivery confirmation needs review.');
        $this->assertPolicy($row, $policy, ['evidence' => $e, 'input' => $r['input']]);
    }

    public function reference(ModernForestryMobileCustomerSession $session): string
    {
        $id = (string) ($session->identity['shopify_customer_id'] ?? '');
        if (preg_match('/\A[1-9][0-9]*\z/', $id)) {
            $id = 'gid://shopify/Customer/'.$id;
        }
        abort_unless(($session->identity['source_type'] ?? null) === 'shopify_customer' && preg_match('#\Agid://shopify/Customer/[1-9][0-9]*\z#', $id), 404);

        return $id;
    }

    private function key(SubscriptionBillingAgreement $row, ModernForestryMobileCustomerSession $session, string $uuid): string
    {
        return 'subscription-customer-delivery-review:'.hash('sha256', implode('|', [$row->tenant_id, $row->id, $this->reference($session), $session->profile->id, $uuid]));
    }

    private function seal(array $evidence): string
    {
        unset($evidence['review_hash']);

        return SubscriptionEvidence::digest(SubscriptionEvidence::canonical($evidence));
    }

    private function cached(string $key): ?array
    {
        $value = Cache::get($key);
        if ($value === null) {
            return null;
        }
        try {
            abort_unless(is_string($value) && strlen($value) <= 1048576, 409);
            $review = json_decode(Crypt::decryptString($value), true, 64, JSON_THROW_ON_ERROR);
            abort_unless(is_array($review), 409);

            return $review;
        } catch (\Throwable) {
            abort(409, 'Your delivery review could not be verified. Start a new review.');
        }
    }
}

<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\User;
use App\Services\Mobile\ModernForestryMobileCustomerSession;

/** A current authenticated customer request or its signed, previously authorized dispatch evidence. */
final readonly class SubscriptionShopifyCustomerActor
{
    private function __construct(public string $id, public array $proof, public bool $interactive) {}

    public static function fromSession(SubscriptionBillingAgreement $row, ModernForestryMobileCustomerSession $session): self
    {
        abort_unless($row->processor === 'shopify', 409);
        $customers = app(SubscriptionCustomerAmendmentService::class);
        $policy = $customers->policy($row, $session);

        return new self($customers->reference($session), ['version' => 1, 'profile_id' => $session->profile->id,
            'customer_gid' => $customers->reference($session), 'policy_action_id' => $policy->id, 'policy_uuid' => $policy->request_uuid,
            'policy_hash' => $policy->request['reviewed_hash'], 'merchant_reviewer_id' => (int) $policy->actor_reference], true);
    }

    public static function fromJournal(SubscriptionBillingAgreement $row, SubscriptionBillingAction $request): self
    {
        $hash = $request->after_state['authorization_hash'] ?? null;
        $proof = $request->request['customer_actor'] ?? [];
        $uuid = $request->request['request_uuid'] ?? '';
        abort_unless($request->tenant_id === $row->tenant_id && $request->agreement_id === $row->id
            && $request->kind === 'shopify_update_requested' && $request->request_uuid === SubscriptionShopifyUpdateHistory::key($row, $uuid, 'requested')
            && $request->actor_type === 'shopify_customer' && ($proof['version'] ?? null) === 1
            && ($proof['customer_gid'] ?? null) === $request->actor_reference
            && $request->actor_reference === 'gid://shopify/Customer/'.SubscriptionBillingAuthorization::original($row)['terms']['shopify_customer_id']
            && is_int($proof['profile_id'] ?? null) && $proof['profile_id'] > 0 && is_int($proof['merchant_reviewer_id'] ?? null)
            && is_string($hash) && hash_equals(SubscriptionBillingTermsHistory::seal($request), $hash), 409, 'Retain the original signed customer request.');

        return new self($request->actor_reference, $proof, false);
    }

    public static function forRequest(SubscriptionBillingAgreement $row, ModernForestryMobileCustomerSession $session, SubscriptionBillingAction $request): self
    {
        $retained = self::fromJournal($row, $request);
        app(SubscriptionCustomerAmendmentService::class)->assertIdentity($row, $session);
        abort_unless($retained->id === app(SubscriptionCustomerAmendmentService::class)->reference($session)
            && $retained->proof['profile_id'] === $session->profile->id, 404);

        return new self($retained->id, $retained->proof, true);
    }

    public function assertCurrent(SubscriptionBillingAgreement $row, bool $edit, bool $currentRead): void
    {
        abort_unless($row->processor === 'shopify', 409);
        app(SubscriptionCustomerAmendmentService::class)->assertSubject($row, $this->proof['profile_id'], $this->id, $currentRead);
        if (! $edit) {
            return;
        }
        abort_unless(config('subscriptions.replacement_customer_delivery_enabled') === true, 423, 'Delivery changes are temporarily unavailable.');
        $policy = app(SubscriptionDeliveryPolicyService::class)->latest($row, $currentRead);
        abort_unless($policy && $policy->id === $this->proof['policy_action_id'] && $policy->request_uuid === $this->proof['policy_uuid']
            && $policy->request['reviewed_hash'] === $this->proof['policy_hash'] && $policy->request['policy']['enabled']
            && (int) $policy->actor_reference === $this->proof['merchant_reviewer_id'], 409, 'Our delivery options changed. Start a new review.');
        app(SubscriptionDeliveryPolicyService::class)->authorize($row, User::findOrFail($this->proof['merchant_reviewer_id']), $currentRead);
    }

    public function permits(SubscriptionBillingAgreement $row, array $input, bool $currentRead): void
    {
        $this->assertCurrent($row, true, $currentRead);
        abort_unless(($input['policy_reference'] ?? null) === 'delivery-policy:'.$this->proof['policy_uuid']
            && ($input['fixed_shipping_reviewed'] ?? null) === true, 409, 'Retain the merchant policy for this customer review.');
        app(SubscriptionDeliveryPolicyService::class)->permits($row, app(SubscriptionDeliveryPolicyService::class)->latest($row, $currentRead), $input);
    }
}

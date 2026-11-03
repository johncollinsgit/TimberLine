<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionBillingCycle;
use App\Models\SubscriptionPilotMember;
use App\Models\User;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** Customer verification can continue only an already-dispatched original native attempt. */
class SubscriptionCustomerPaymentRecoveryService
{
    public function enabled(SubscriptionBillingAgreement $row): bool
    {
        return $row->processor === 'shopify' && config('subscriptions.replacement_customer_payment_recovery_enabled') === true
            && in_array($row->tenant_id, config('subscriptions.replacement_tenant_ids', []), true)
            && in_array($row->member_id, config('subscriptions.replacement_member_ids', []), true);
    }

    public function status(ModernForestryMobileCustomerSession $session, string $publicId): array
    {
        return $this->checked($session, $publicId, fn ($row, $cycle) => $this->summary($session, $row, $cycle));
    }

    public function begin(ModernForestryMobileCustomerSession $session, string $publicId, string $reviewHash): array
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/D', $reviewHash), 422, 'Review this payment before continuing verification.');

        return $this->checked($session, $publicId, function ($row, $cycle) use ($session, $reviewHash) {
            $summary = $this->summary($session, $row, $cycle);
            if (! $summary['can_authenticate']) {
                return $summary;
            }
            abort_unless(hash_equals($summary['review_hash'], $reviewHash), 409, 'The original payment verification changed. Check its current status before continuing.');
            $challenge = $cycle->reconciliation['latest_shopify_receipt']['customer_action'];
            $request = ['version' => 1, 'cycle_id' => $cycle->id, 'cycle_public_id' => $cycle->public_id,
                'original_request_uuid' => $cycle->request_uuid, 'original_attempt_id' => $cycle->provider_reference,
                'profile_id' => $session->profile->id, 'review_hash' => $reviewHash,
                'amount_cents' => $cycle->amount_cents, 'currency' => $cycle->currency];
            $uuid = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'everbranch:original-payment-verification:'.$cycle->public_id.':'.$session->profile->id.':'.$reviewHash);
            $prior = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)->where('request_uuid', $uuid)->lockForUpdate()->first();
            if ($prior) {
                abort_unless($prior->kind === 'shopify_challenge_link_issued' && $prior->actor_type === 'shopify_customer'
                    && $prior->actor_reference === 'gid://shopify/Customer/'.$cycle->terms['shopify_customer_id']
                    && SubscriptionEvidence::canonical($prior->request) === SubscriptionEvidence::canonical($request)
                    && is_string($prior->after_state['authorization_hash'] ?? null)
                    && hash_equals(SubscriptionBillingTermsHistory::seal($prior), $prior->after_state['authorization_hash']), 409, 'Retain the original payment-verification evidence.');
            } else {
                $action = new SubscriptionBillingAction(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id,
                    'request_uuid' => $uuid, 'kind' => 'shopify_challenge_link_issued', 'actor_type' => 'shopify_customer',
                    'actor_reference' => 'gid://shopify/Customer/'.$cycle->terms['shopify_customer_id'], 'expected_revision' => $cycle->agreement_revision,
                    'request' => $request, 'before_state' => [], 'after_state' => ['issued_at' => now()->toISOString()]]);
                $action->after_state = $action->after_state + ['authorization_hash' => SubscriptionBillingTermsHistory::seal($action)];
                $action->save();
            }

            return $summary + ['redirect_url' => $challenge['url']];
        });
    }

    private function checked(ModernForestryMobileCustomerSession $session, string $publicId, \Closure $work): array
    {
        abort_unless(DB::transactionLevel() === 0, 409, 'Check the original payment outside an enclosing transaction.');
        abort_unless(Str::isUuid($publicId), 404);
        $row = app(SubscriptionReplacementCustomerAccess::class)->agreement($session);
        abort_unless($row && $this->enabled($row), 404);
        $cycle = $this->locked($row, $session, strtolower($publicId), fn ($current, $cycle) => $cycle);
        try {
            // This method only reads/reconciles the original attempt. It never calls collect, bill or adoption.
            app(SubscriptionShopifyExecutionService::class)->reconcile($cycle);
        } catch (\Throwable) {
            abort(503, 'We could not check this original payment. Please check again or contact us before paying another way.');
        }

        return $this->locked($row, $session, strtolower($publicId), $work);
    }

    private function locked(SubscriptionBillingAgreement $row, ModernForestryMobileCustomerSession $session, string $publicId, \Closure $work): array|SubscriptionBillingCycle
    {
        return DB::transaction(function () use ($row, $session, $publicId, $work) {
            SubscriptionPilotMember::query()->forTenantId($row->tenant_id)->whereKey($row->member_id)->lockForUpdate()->firstOrFail();
            $current = SubscriptionBillingAgreement::query()->forTenantId($row->tenant_id)->whereKey($row->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->enabled($current), 404);
            app(SubscriptionCustomerAmendmentService::class)->assertIdentity($current, $session, true);
            $actor = User::query()->whereKey($current->activated_by)->lockForUpdate()->firstOrFail();
            $membership = DB::table('tenant_user')->where('tenant_id', $current->tenant_id)->where('user_id', $actor->id)->lockForUpdate()->first();
            abort_unless($actor->is_active && $actor->email_verified_at && $membership && $membership->membership_active
                && in_array(strtolower($membership->role), ['owner', 'tenant_owner', 'admin'], true), 423, 'Our team needs to review this payment before verification can continue.');
            foreach (['tenant_access_profiles', 'tenant_access_addons', 'tenant_module_states', 'tenant_module_entitlements'] as $table) {
                DB::table($table)->where('tenant_id', $current->tenant_id)->orderBy('id')->lockForUpdate()->get();
            }
            abort_unless(app()->build(TenantModuleAccessResolver::class)->module($current->tenant_id, 'subscriptions')['has_access'], 403);
            app(SubscriptionReplacementAccess::class)->owner($current->tenant_id, $actor, $current->member_id, 'setup');
            SubscriptionBillingAuthorization::assertCurrent($current, true);
            app(SubscriptionShopifyBillingTransport::class)->context($current, true);
            $cycle = SubscriptionBillingCycle::query()->forTenantId($current->tenant_id)->where('agreement_id', $current->id)
                ->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            abort_unless($cycle->processor === 'shopify' && $cycle->payment_started_at && $cycle->provider_reference
                && $cycle->method_binding === $current->method_binding
                && $cycle->terms['shopify_customer_id'] === $current->terms['shopify_customer_id']
                && $cycle->terms['shopify_store_id'] === $current->terms['shopify_store_id']
                && $cycle->terms['shopify_contract_id'] === data_get($current->authorization_evidence, 'shopify_adoption.contract_id')
                && $cycle->amount_cents === $cycle->terms['amount_cents'] && $cycle->currency === $cycle->terms['currency'], 404);

            return $work($current, $cycle);
        });
    }

    private function summary(ModernForestryMobileCustomerSession $session, SubscriptionBillingAgreement $row, SubscriptionBillingCycle $cycle): array
    {
        $receipt = $cycle->reconciliation['latest_shopify_receipt'] ?? [];
        $challenge = $receipt['customer_action'] ?? null;
        $safeChallenge = is_array($challenge) ? SubscriptionShopifyBillingProtocol::paymentChallenge([
            '__typename' => 'SubscriptionBillingAttemptPaymentChallenge', 'nextActionUrl' => $challenge['url'] ?? null,
            'status' => $challenge['status'] ?? null]) : null;
        $fresh = is_string($receipt['checked_at'] ?? null) && \Carbon\CarbonImmutable::parse($receipt['checked_at'])->betweenIncluded(now()->subMinute(), now()->addSeconds(5));
        $available = $fresh && $cycle->payment_status === 'requires_action' && ! $cycle->paid_at
            && ($receipt['status'] ?? null) === 'requires_action' && ($receipt['attempt_id'] ?? null) === $cycle->provider_reference
            && is_array($challenge) && $safeChallenge !== null && SubscriptionEvidence::canonical($challenge) === SubscriptionEvidence::canonical($safeChallenge);
        $hash = SubscriptionEvidence::digest(SubscriptionEvidence::canonical(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id,
            'profile_id' => $session->profile->id, 'cycle_id' => $cycle->id, 'cycle_public_id' => $cycle->public_id,
            'original_request_uuid' => $cycle->request_uuid, 'attempt_id' => $cycle->provider_reference,
            'amount_cents' => $cycle->amount_cents, 'currency' => $cycle->currency, 'terms' => $cycle->terms,
            'method' => $cycle->method_binding, 'challenge' => $challenge]));

        return ['cycle_public_id' => $cycle->public_id, 'cycle_date' => $cycle->cycle_date->toDateString(),
            'amount_cents' => $cycle->amount_cents, 'currency' => $cycle->currency, 'status' => $cycle->payment_status,
            'provider_payment_received' => in_array($receipt['status'] ?? null, ['succeeded', 'refunded', 'disputed'], true),
            'order_confirmed' => $cycle->handoff_status === 'confirmed', 'checked_at' => $receipt['checked_at'] ?? null,
            'can_authenticate' => $available, 'review_hash' => $hash, 'future_status' => $row->status, 'sends_new_charge_attempt' => false];
    }
}

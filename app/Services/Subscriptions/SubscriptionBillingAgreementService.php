<?php

namespace App\Services\Subscriptions;

use App\Models\IntegrationConnection;
use App\Models\ShopifyStore;
use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionBillingCycle;
use App\Models\SubscriptionInstallation;
use App\Models\SubscriptionPilotEnrollment;
use App\Models\SubscriptionPilotMember;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Provider-neutral preparation and future schedule controls. No payment or provider mutation. */
class SubscriptionBillingAgreementService
{
    public function review(int $tenantId, User $actor, int $memberId, array $input): array
    {
        $candidate = $this->candidate($tenantId, $actor, $memberId, $input);

        return ['terms_hash' => $candidate['terms_hash'], 'processor' => $candidate['processor'],
            'amount_cents' => $candidate['terms']['amount_cents'], 'currency' => $candidate['terms']['currency'],
            'first_billing_date' => $candidate['terms']['first_billing_date'], 'method_verified' => false,
            'reauthorization_required' => null, 'billing_owner' => 'recharge', 'collection_enabled' => false];
    }

    public function prepare(int $tenantId, User $actor, int $memberId, array $input, string $expectedHash): SubscriptionBillingAgreement
    {
        $candidate = $this->candidate($tenantId, $actor, $memberId, $input);
        abort_unless(hash_equals($candidate['terms_hash'], $expectedHash), 409, 'The reviewed terms or original payment binding changed.');

        return DB::transaction(function () use ($tenantId, $actor, $memberId, $candidate, $input) {
            $this->owner($tenantId, $actor->fresh(), $memberId, 'setup');
            $member = SubscriptionPilotMember::query()->forTenantId($tenantId)->whereKey($memberId)->lockForUpdate()->firstOrFail();
            $freshCandidate = $this->candidate($tenantId, $actor, $memberId, $input);
            abort_unless(hash_equals($candidate['terms_hash'], $freshCandidate['terms_hash']), 409, 'The original source or processor changed during preparation.');
            abort_unless($member->billing_owner === 'recharge' && $member->status === 'prepared', 409, 'Existing billing ownership needs review.');
            abort_if(SubscriptionPilotEnrollment::query()->forTenantId($tenantId)->where('member_id', $memberId)->whereIn('status', ['authorized', 'active', 'paused'])->exists(), 409, 'A Stripe pilot already has payment authority for this member.');
            $current = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->where('member_id', $memberId)->lockForUpdate()->first();
            if ($current) {
                abort_unless(SubscriptionEvidence::canonical($current->terms) === SubscriptionEvidence::canonical($candidate['terms'])
                    && SubscriptionEvidence::canonical($current->method_binding) === SubscriptionEvidence::canonical($candidate['method_binding'])
                    && collect($current->authorization_evidence)->only(['reference', 'continuing_authorization_reviewed_by', 'source_terms_reviewed_by'])->all()
                        === collect($candidate['authorization_evidence'])->only(['reference', 'continuing_authorization_reviewed_by', 'source_terms_reviewed_by'])->all(), 409, 'The retained agreement differs from this preparation.');

                return $current;
            }

            return SubscriptionBillingAgreement::create($candidate + ['tenant_id' => $tenantId, 'member_id' => $memberId,
                'prepared_by' => $actor->id, 'billing_anchor_date' => $candidate['terms']['first_billing_date'], 'status' => 'prepared'])->fresh();
        });
    }

    /** Acts only on a separately activated Everbranch agreement; never replays old lifecycle intents. */
    public function change(int $tenantId, User $actor, int $agreementId, string $kind, string $requestUuid, int $expectedRevision): SubscriptionBillingAction
    {
        $scope = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);
        $this->owner($tenantId, $actor, $scope->member_id, 'servicing');
        abort_unless(in_array($kind, ['pause', 'resume', 'skip', 'cancel'], true) && Str::isUuid($requestUuid) && $expectedRevision >= 1, 422, 'Choose a supported action with a fresh review and request ID.');
        $requestUuid = strtolower($requestUuid);

        return DB::transaction(function () use ($tenantId, $actor, $agreementId, $kind, $requestUuid, $expectedRevision) {
            $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->whereKey($agreementId)->lockForUpdate()->firstOrFail();
            $this->owner($tenantId, $actor->fresh(), $row->member_id, 'servicing');
            $member = SubscriptionPilotMember::query()->forTenantId($tenantId)->whereKey($row->member_id)->lockForUpdate()->firstOrFail();
            $prior = SubscriptionBillingAction::query()->forTenantId($tenantId)->where('agreement_id', $row->id)->where('request_uuid', $requestUuid)->first();
            if ($prior) {
                abort_unless($prior->kind === $kind && $prior->expected_revision === $expectedRevision && $prior->actor_type === 'user' && $prior->actor_reference === (string) $actor->id, 409, 'This request ID belongs to a different action.');

                return $prior;
            }
            abort_unless($row->activated_at && $row->method_verified_at && $member->billing_owner === 'everbranch'
                && in_array($row->status, ['active', 'paused', 'cancelled'], true), 409, 'This agreement has not taken over billing from Recharge.');
            abort_unless($row->revision === $expectedRevision, 409, 'The subscription changed. Review its current state.');
            $inFlight = SubscriptionBillingCycle::query()->forTenantId($tenantId)->where('agreement_id', $row->id)
                ->whereIn('payment_status', ['prepared', 'sending', 'unknown', 'processing', 'review'])->exists();
            abort_if($kind !== 'cancel' && $inFlight, 409, 'An original billing attempt needs reconciliation before a schedule change.');
            $before = $this->state($row);
            if ($kind === 'cancel') {
                abort_unless(in_array($row->status, ['active', 'paused'], true), 409, 'Future billing is already cancelled.');
                $row->fill(['status' => 'cancelled', 'next_billing_date' => null, 'resume_on' => null]);
            } elseif ($kind === 'pause') {
                abort_unless($row->status === 'active', 409, 'Only an active agreement can be paused.');
                $row->fill(['status' => 'paused', 'resume_on' => null]);
            } elseif ($kind === 'resume') {
                abort_unless($row->status === 'paused', 409, 'Only a paused agreement can resume.');
                $earliest = max(now($row->terms['timezone'])->toDateString(), $row->next_billing_date?->toDateString() ?? '');
                $row->fill(['status' => 'active', 'next_billing_date' => $this->nextAnchor($row, $earliest, true), 'resume_on' => null]);
            } else {
                abort_unless($row->status === 'active' && $row->next_billing_date
                    && $row->next_billing_date->toDateString() >= now($row->terms['timezone'])->toDateString(), 409, 'Review the next future billing cycle before skipping it.');
                $row->next_billing_date = $this->nextAnchor($row, $row->next_billing_date->toDateString(), false);
            }
            $row->revision++;
            $row->save();
            $member->update(['status' => $row->status]);

            return SubscriptionBillingAction::create(['tenant_id' => $tenantId, 'agreement_id' => $row->id,
                'request_uuid' => $requestUuid, 'kind' => $kind, 'actor_type' => 'user', 'actor_reference' => (string) $actor->id,
                'expected_revision' => $expectedRevision, 'request' => ['kind' => $kind, 'expected_revision' => $expectedRevision],
                'before_state' => $before, 'after_state' => $this->state($row) + ['original_payment_requires_review' => $inFlight]]);
        });
    }

    public function state(SubscriptionBillingAgreement $row): array
    {
        return ['status' => $row->status, 'revision' => $row->revision, 'processor' => $row->processor,
            'next_billing_date' => $row->next_billing_date?->toDateString(), 'terms_hash' => $row->terms_hash,
            'method_verified' => $row->method_verified_at !== null, 'activated' => $row->activated_at !== null];
    }

    private function nextAnchor(SubscriptionBillingAgreement $row, string $after, bool $allowToday): string
    {
        $anchor = CarbonImmutable::parse($row->billing_anchor_date->toDateString(), $row->terms['timezone']);
        for ($index = 0; $index <= 1200; $index++) {
            $date = $anchor->addMonthsNoOverflow($index * $row->terms['billing_months'])->toDateString();
            if ($date > $after || ($allowToday && $date === $after && ($date > now($row->terms['timezone'])->toDateString() || now($row->terms['timezone'])->hour < $row->terms['billing_hour']))) {
                return $date;
            }
        }
        abort(409, 'The retained billing anchor needs review.');
    }

    private function candidate(int $tenantId, User $actor, int $memberId, array $input): array
    {
        $this->owner($tenantId, $actor, $memberId, 'setup');
        $member = SubscriptionPilotMember::query()->forTenantId($tenantId)->findOrFail($memberId);
        $data = Validator::make($input, [
            'processor' => ['required', 'in:braintree,shopify'], 'method_binding' => ['required', 'array'],
            'shipping_cents' => ['required', 'integer', 'min:0', 'max:10000000'], 'tax_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
            'tax_rate_bps' => ['sometimes', 'integer', 'min:0', 'max:10000'], 'tax_shipping' => ['sometimes', 'boolean'],
            'shipping_address' => ['required', 'array:firstName,lastName,company,address1,address2,city,provinceCode,countryCode,zip,phone'],
            'shipping_address.firstName' => ['required', 'string', 'max:100'], 'shipping_address.lastName' => ['required', 'string', 'max:100'],
            'shipping_address.address1' => ['required', 'string', 'max:255'], 'shipping_address.address2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['required', 'string', 'max:100'], 'shipping_address.provinceCode' => ['required', 'string', 'size:2'],
            'shipping_address.countryCode' => ['required', 'in:US'], 'shipping_address.zip' => ['required', 'regex:/\A[0-9]{5}(?:-[0-9]{4})?\z/'],
            'shipping_address.company' => ['nullable', 'string', 'max:255'], 'shipping_address.phone' => ['nullable', 'string', 'max:30'],
            'authorization_reference' => ['required', 'string', 'regex:/\A[A-Za-z0-9_.:\/-]{1,190}\z/'],
            'continuing_authorization_reviewed' => ['required', 'accepted'], 'source_terms_reviewed' => ['required', 'accepted'],
        ])->validate();
        $store = ShopifyStore::query()->forTenantId($tenantId)->findOrFail($member->shopify_store_id);
        abort_unless($member->billing_owner === 'recharge' && $member->status === 'prepared'
            && $store->shop_domain === $member->details['shop_domain'] && preg_match('/\A[a-z0-9-]+\.myshopify\.com\z/', $store->shop_domain)
            && $member->target_date->toDateString() >= now($member->timezone)->toDateString(), 409, 'Review current source ownership, store and a future billing date.');
        $lines = $member->details['lines'];
        $months = array_unique(array_column($lines, 'billing_months'));
        abort_unless(count($months) === 1 && collect($lines)->every(fn ($line) => ! $line['prepaid'] && $line['delivery_months'] === $line['billing_months']), 409, 'Prepaid or mixed schedules require their separate delivery implementation.');
        $subtotal = array_sum(array_map(fn ($line) => $line['quantity'] * $line['unit_price_cents'], $lines));
        $amount = $subtotal + $data['shipping_cents'] + $data['tax_cents'];
        $taxBase = $subtotal + (! empty($data['tax_shipping']) ? $data['shipping_cents'] : 0);
        abort_unless((int) round($taxBase * ($data['tax_rate_bps'] ?? 0) / 10000) === $data['tax_cents'], 409, 'Review the exact source tax rate and taxable shipping before preparing billing.');
        abort_unless($amount > 0 && $amount <= 10000000 && $amount === $member->expected_amount_cents, 409, 'Reviewed source amount must exactly match products, shipping and tax.');
        $binding = $this->methodBinding($member, $data['processor'], $data['method_binding']);
        $terms = ['version' => 1, 'member_id' => $member->id, 'shopify_store_id' => $store->id, 'shop_domain' => $store->shop_domain,
            'shopify_customer_id' => $member->shopify_customer_id, 'recharge_customer_id' => $member->recharge_customer_id,
            'lines' => $lines, 'currency' => $member->currency, 'amount_cents' => $amount, 'subtotal_cents' => $subtotal,
            'shipping_cents' => $data['shipping_cents'], 'tax_cents' => $data['tax_cents'], 'shipping_address' => $data['shipping_address'],
            'tax_rate_bps' => (int) ($data['tax_rate_bps'] ?? 0), 'tax_shipping' => (bool) ($data['tax_shipping'] ?? false),
            'billing_months' => (int) reset($months), 'first_billing_date' => $member->target_date->toDateString(), 'billing_hour' => 9,
            'timezone' => $member->timezone, 'candle_cash_allowed' => false];
        $authorization = ['status' => 'review_required', 'reference' => $data['authorization_reference'],
            'continuing_authorization_reviewed_by' => $actor->id, 'source_terms_reviewed_by' => $actor->id,
            'provider_verified' => false, 'source_stop_verified' => false];
        $hash = SubscriptionEvidence::digest(SubscriptionEvidence::canonical(['terms' => $terms, 'binding' => $binding, 'authorization' => $authorization]));

        return ['processor' => $data['processor'], 'terms' => $terms, 'method_binding' => $binding, 'authorization_evidence' => $authorization, 'terms_hash' => $hash];
    }

    private function methodBinding(SubscriptionPilotMember $member, string $processor, array $input): array
    {
        if ($processor === 'braintree') {
            $data = Validator::make(['binding' => $input], ['binding' => ['array:connection_id,customer_id,payment_method_token,source_payment_reference'],
                'binding.connection_id' => ['required', 'integer', 'min:1'], 'binding.customer_id' => ['required', 'regex:/\A[A-Za-z0-9_-]{1,100}\z/'],
                'binding.payment_method_token' => ['required', 'regex:/\A[A-Za-z0-9_-]{1,36}\z/'],
                'binding.source_payment_reference' => ['required', 'regex:/\A[A-Za-z0-9_-]{1,100}\z/']])->validate()['binding'];
            $data['connection_id'] = (int) $data['connection_id'];
            $connection = IntegrationConnection::query()->forTenant($member->tenant_id)->where('provider', 'braintree')->where('status', 'connected')
                ->where('metadata->purpose', 'merchant_payment_verification')->lockForUpdate()->findOrFail($data['connection_id']);
            $meta = $connection->metadata;
            abort_unless(data_get($meta, 'currency') === $member->currency && data_get($meta, 'account_read_verified') === true
                && in_array(data_get($meta, 'environment'), ['production', 'sandbox'], true) && Str::isUuid((string) data_get($meta, 'credential_revision'))
                && preg_match('/\A[A-Za-z0-9_-]{3,100}\z/', (string) data_get($meta, 'merchant_id'))
                && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', (string) data_get($meta, 'merchant_account_id'))
                && $connection->external_account_id === $meta['environment'].'|'.$meta['merchant_id'].'|'.$meta['merchant_account_id'], 409, 'Verify the original Braintree merchant account and currency first.');

            return $data + ['processor' => 'braintree', 'credential_revision' => $meta['credential_revision'], 'environment' => $meta['environment'],
                'merchant_id' => $meta['merchant_id'], 'merchant_account_id' => $meta['merchant_account_id']];
        }
        $data = Validator::make(['binding' => $input], ['binding' => ['array:installation_id,contract_gid,payment_method_gid'],
            'binding.installation_id' => ['required', 'integer', 'min:1'], 'binding.contract_gid' => ['required', 'regex:#\Agid://shopify/SubscriptionContract/[1-9][0-9]*\z#'],
            'binding.payment_method_gid' => ['required', 'regex:#\Agid://shopify/CustomerPaymentMethod/[A-Za-z0-9_-]{1,150}\z#']])->validate()['binding'];
        $data['installation_id'] = (int) $data['installation_id'];
        $installation = SubscriptionInstallation::query()->forTenantId($member->tenant_id)->where('shopify_store_id', $member->shopify_store_id)
            ->where('status', 'verified')->findOrFail($data['installation_id']);
        abort_unless($installation->shop_domain === $member->details['shop_domain'] && $installation->active_credential_revision_id, 409, 'Verify the original Shopify subscription app first.');

        return $data + ['processor' => 'shopify', 'credential_revision_id' => $installation->active_credential_revision_id,
            'shop_domain' => $installation->shop_domain, 'app_gid' => $installation->app_gid];
    }

    private function owner(int $tenantId, User $actor, int $memberId, string $gate): void
    {
        app(SubscriptionReplacementAccess::class)->owner($tenantId, $actor, $memberId, $gate);
    }
}

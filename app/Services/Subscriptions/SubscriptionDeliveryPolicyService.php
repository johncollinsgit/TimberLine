<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionPilotMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Explicit merchant coverage for customer delivery edits, separate from payment authorization. */
class SubscriptionDeliveryPolicyService
{
    public const PROVINCES = ['AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY'];

    public function preview(int $tenantId, User $actor, int $agreementId, array $input): array
    {
        $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);
        $this->authorize($row, $actor);
        SubscriptionBillingAuthorization::assertCurrent($row);
        $request = $this->candidate($row, $actor, $input);

        return ['agreement_id' => $row->id, 'member_id' => $row->member_id, 'revision' => $row->revision,
            'currency' => $row->terms['currency'], 'fixed_shipping_cents' => $row->terms['shipping_cents'], 'current_amount_cents' => $row->terms['amount_cents'],
            'policy' => $request['policy'], 'review_hash' => $this->hash($request),
            'changes_billing' => false, 'provider_requests' => false];
    }

    public function record(int $tenantId, User $actor, int $agreementId, array $input, string $hash, string $uuid, int $revision): SubscriptionBillingAction
    {
        $scope = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);
        $this->authorize($scope, $actor);
        $policy = $this->normalize($input);
        abort_unless(Str::isUuid($uuid) && $revision >= 1 && preg_match('/\A[a-f0-9]{64}\z/', $hash), 422);
        $uuid = strtolower($uuid);

        return DB::transaction(function () use ($scope, $actor, $policy, $hash, $uuid, $revision) {
            SubscriptionPilotMember::query()->forTenantId($scope->tenant_id)->whereKey($scope->member_id)->lockForUpdate()->firstOrFail();
            $row = SubscriptionBillingAgreement::query()->forTenantId($scope->tenant_id)->whereKey($scope->id)->lockForUpdate()->firstOrFail();
            $this->authorize($row, $actor, true);
            SubscriptionBillingAuthorization::assertCurrent($row, true);
            $prior = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)->where('request_uuid', $uuid)->lockForUpdate()->first();
            if ($prior) {
                $this->evidence($row, $prior);
                abort_unless($prior->actor_reference === (string) $actor->id && $prior->expected_revision === $revision
                    && $prior->request['reviewed_hash'] === $hash && SubscriptionEvidence::canonical($prior->request['policy']) === SubscriptionEvidence::canonical($policy), 409, 'This request ID belongs to another policy review.');

                return $prior;
            }
            $request = $this->candidate($row, $actor, $policy, true);
            abort_unless($row->revision === $revision && hash_equals($this->hash($request), $hash), 409, 'Review the current merchant delivery policy and agreement.');
            $request['reviewed_hash'] = $hash;
            $action = new SubscriptionBillingAction(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'request_uuid' => $uuid,
                'kind' => 'delivery_policy_reviewed', 'actor_type' => 'user', 'actor_reference' => (string) $actor->id, 'expected_revision' => $revision,
                'request' => $request, 'before_state' => ['previous_policy' => $request['subject']['previous_policy']], 'after_state' => ['policy_hash' => $hash]]);
            $action->after_state = $action->after_state + ['authorization_hash' => SubscriptionBillingTermsHistory::seal($action)];
            $action->save();
            $evidence = $row->authorization_evidence;
            $evidence['customer_delivery_policy'] = $this->pointer($action);
            $row->update(['authorization_evidence' => $evidence]);

            return $action;
        });
    }

    public function latest(SubscriptionBillingAgreement $row, bool $currentRead = false): ?SubscriptionBillingAction
    {
        $pointer = $row->authorization_evidence['customer_delivery_policy'] ?? null;
        $action = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)
            ->where('kind', 'delivery_policy_reviewed')->latest('id')->when($currentRead, fn ($q) => $q->lockForUpdate())->first();
        if (! $pointer && ! $action) {
            return null;
        }
        abort_unless($action && is_array($pointer) && SubscriptionEvidence::canonical($pointer) === SubscriptionEvidence::canonical($this->pointer($action)),
            409, 'The current merchant delivery policy needs review.');
        $this->evidence($row, $action);

        return $action;
    }

    public function authorize(SubscriptionBillingAgreement $row, User $actor, bool $currentRead = false): void
    {
        if ($row->processor === 'shopify') {
            app(SubscriptionShopifyUpdateService::class)->authorizePolicy($row, $actor, $currentRead);

            return;
        }
        app(SubscriptionBillingAmendmentService::class)->authorize($row, $actor, $currentRead);
    }

    public function evidence(SubscriptionBillingAgreement $row, SubscriptionBillingAction $action): void
    {
        $request = $action->request;
        abort_unless($action->tenant_id === $row->tenant_id && $action->agreement_id === $row->id && $action->kind === 'delivery_policy_reviewed'
            && $action->actor_type === 'user' && ctype_digit($action->actor_reference) && ($request['version'] ?? null) === 1
            && ($request['reviewed_by'] ?? null) === (int) $action->actor_reference && $action->expected_revision >= 1
            && data_get($request, 'subject.revision') === $action->expected_revision
            && SubscriptionEvidence::canonical(collect($request['subject'] ?? [])->only(array_keys($this->scope($row)))->all()) === SubscriptionEvidence::canonical($this->scope($row))
            && is_array($request['policy'] ?? null) && SubscriptionEvidence::canonical($request['policy']) === SubscriptionEvidence::canonical($this->normalize($request['policy']))
            && is_string($request['reviewed_hash'] ?? null) && hash_equals($this->hash($request), $request['reviewed_hash'])
            && ($action->after_state['policy_hash'] ?? null) === $request['reviewed_hash']
            && is_string($action->after_state['authorization_hash'] ?? null)
            && hash_equals(SubscriptionBillingTermsHistory::seal($action), $action->after_state['authorization_hash']),
            409, 'The retained merchant delivery policy differs from its original review.');
    }

    public function permits(SubscriptionBillingAgreement $row, SubscriptionBillingAction $action, array $input): void
    {
        $this->evidence($row, $action);
        $policy = $action->request['policy'];
        $address = $input['shipping_address'] ?? $row->terms['shipping_address'];
        abort_unless($policy['enabled'] && ($address['countryCode'] ?? null) === 'US'
            && in_array($address['provinceCode'] ?? null, $policy['provinces'], true), 423, 'Our team needs to review shipping to this address. Your current delivery is unchanged.');
        foreach ($input['swaps'] ?? [] as $swap) {
            abort_unless($policy['allow_scent_swaps'] && in_array($swap['variant_id'], array_column($policy['scents'], 'variant_id'), true),
                423, 'Choose a scent offered for your membership.');
        }
    }

    private function candidate(SubscriptionBillingAgreement $row, User $actor, array $input, bool $currentRead = false): array
    {
        $policy = $this->normalize($input);
        $current = $this->latest($row, $currentRead);
        abort_unless(! $policy['allow_scent_swaps'] || app(SubscriptionMembershipReviewService::class)->program($row) === 'candle_club', 409, 'Review the original Candle Club membership before offering scent swaps.');

        return ['version' => 1, 'subject' => $this->scope($row) + ['revision' => $row->revision, 'terms_hash' => $row->terms_hash,
            'previous_policy' => $current ? $this->pointer($current) : null], 'policy' => $policy, 'reviewed_by' => $actor->id];
    }

    private function scope(SubscriptionBillingAgreement $row): array
    {
        $original = SubscriptionBillingAuthorization::original($row);

        return ['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'agreement_public_id' => $row->public_id, 'member_id' => $row->member_id,
            'original_terms_hash' => $original['terms_hash'], 'binding_hash' => SubscriptionEvidence::digest(SubscriptionEvidence::canonical($row->method_binding))];
    }

    private function pointer(SubscriptionBillingAction $action): array
    {
        return ['action_id' => $action->id, 'request_uuid' => $action->request_uuid, 'policy_hash' => $action->request['reviewed_hash']];
    }

    private function hash(array $request): string
    {
        unset($request['reviewed_hash']);

        return SubscriptionEvidence::digest(SubscriptionEvidence::canonical($request));
    }

    private function normalize(array $input): array
    {
        $policy = Validator::make(['policy' => $input], ['policy' => ['required', 'array:enabled,provinces,allow_scent_swaps,scents,reference,fixed_shipping_reviewed,continuing_authorization_reviewed'],
            'policy.enabled' => ['required', 'boolean'], 'policy.allow_scent_swaps' => ['required', 'boolean'],
            'policy.provinces' => ['required', 'array', 'min:1', 'max:51'], 'policy.provinces.*' => ['required', 'string', 'distinct', Rule::in(self::PROVINCES)],
            'policy.scents' => ['present', 'array', 'max:100'], 'policy.scents.*' => ['array:variant_id,label'],
            'policy.scents.*.variant_id' => ['required', 'string', 'distinct', 'regex:/\A[1-9][0-9]*\z/'], 'policy.scents.*.label' => ['required', 'string', 'max:255'],
            'policy.reference' => ['required', 'string', 'regex:/\A[A-Za-z0-9_.:\/-]{1,190}\z/'],
            'policy.fixed_shipping_reviewed' => ['required', 'accepted'], 'policy.continuing_authorization_reviewed' => ['required', 'accepted'],
        ])->validate()['policy'];
        $policy['enabled'] = (bool) $policy['enabled'];
        $policy['allow_scent_swaps'] = (bool) $policy['allow_scent_swaps'];
        $policy['fixed_shipping_reviewed'] = $policy['continuing_authorization_reviewed'] = true;
        abort_unless(! $policy['allow_scent_swaps'] || count($policy['scents']) > 0, 422, 'Review the available membership scents.');
        sort($policy['provinces']);
        usort($policy['scents'], fn ($a, $b) => strcmp($a['variant_id'], $b['variant_id']));

        return $policy;
    }
}

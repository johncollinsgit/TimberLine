<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionPilotMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Later membership review is append-only evidence, independent of original payment authority. */
class SubscriptionMembershipReviewService
{
    public function program(SubscriptionBillingAgreement $row): ?string
    {
        $original = SubscriptionBillingAuthorization::original($row);
        if (($original['terms']['membership_program'] ?? null) === 'candle_club'
            && data_get($original, 'authorization.membership_review.reviewed_by') === $row->prepared_by
            && is_string(data_get($original, 'authorization.membership_review.reference'))) {
            return 'candle_club';
        }
        $reviews = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)
            ->where('kind', 'membership_review')->limit(2)->get();
        abort_if($reviews->count() > 1, 409, 'More than one membership supplement needs review.');
        $review = $reviews->first();
        if (! $review) {
            return null;
        }
        $request = $review->request;
        $subject = $this->subject($row);
        abort_unless($review->actor_type === 'user' && ctype_digit($review->actor_reference)
            && ($request['reviewed_by'] ?? null) === (int) $review->actor_reference
            && ($request['expected_revision'] ?? null) === $review->expected_revision
            && ($request['membership_program'] ?? null) === 'candle_club'
            && ($request['source_membership_reviewed'] ?? null) === true
            && is_string($request['membership_reference'] ?? null)
            && SubscriptionEvidence::canonical(collect($request)->only(array_keys($subject))->all()) === SubscriptionEvidence::canonical($subject)
            && ($review->after_state['membership_program'] ?? null) === 'candle_club'
            && is_string($review->after_state['review_hash'] ?? null)
            && hash_equals(SubscriptionEvidence::digest(SubscriptionEvidence::canonical($request)), $review->after_state['review_hash']),
            409, 'The retained membership supplement differs from its original customer or review.');

        return 'candle_club';
    }

    public function preview(int $tenantId, User $actor, int $agreementId, array $input): array
    {
        $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);
        $request = $this->candidate($row, $actor, $input, $row->revision);

        return ['agreement_id' => $row->id, 'member_id' => $row->member_id, 'revision' => $row->revision,
            'membership_program' => 'candle_club', 'already_reviewed' => $this->program($row) !== null,
            'original_terms_hash' => $request['original_terms_hash'], 'review_hash' => SubscriptionEvidence::digest(SubscriptionEvidence::canonical($request)),
            'provider_requests' => false, 'payment_authorization_changed' => false, 'billing_changed' => false];
    }

    public function record(int $tenantId, User $actor, int $agreementId, array $input, string $reviewHash, string $requestUuid, int $expectedRevision): SubscriptionBillingAction
    {
        $scope = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);
        app(SubscriptionReplacementAccess::class)->owner($tenantId, $actor, $scope->member_id, 'setup');
        abort_unless(Str::isUuid($requestUuid) && $expectedRevision >= 1, 422, 'Provide the reviewed revision and a unique request ID.');
        $requestUuid = strtolower($requestUuid);

        return DB::transaction(function () use ($scope, $actor, $input, $reviewHash, $requestUuid, $expectedRevision) {
            SubscriptionPilotMember::query()->forTenantId($scope->tenant_id)->whereKey($scope->member_id)->lockForUpdate()->firstOrFail();
            $row = SubscriptionBillingAgreement::query()->forTenantId($scope->tenant_id)->whereKey($scope->id)->lockForUpdate()->firstOrFail();
            $request = $this->candidate($row, $actor->fresh(), $input, $expectedRevision);
            $hash = SubscriptionEvidence::digest(SubscriptionEvidence::canonical($request));
            abort_unless(hash_equals($hash, $reviewHash), 409, 'The reviewed membership, customer or owner changed.');
            $prior = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)
                ->where('request_uuid', $requestUuid)->lockForUpdate()->first();
            if ($prior) {
                abort_unless($prior->kind === 'membership_review' && $prior->actor_type === 'user'
                    && $prior->actor_reference === (string) $actor->id && $prior->expected_revision === $expectedRevision
                    && SubscriptionEvidence::canonical($prior->request) === SubscriptionEvidence::canonical($request)
                    && ($prior->after_state['membership_program'] ?? null) === 'candle_club'
                    && ($prior->after_state['review_hash'] ?? null) === $hash, 409, 'This request ID belongs to another action.');

                return $prior;
            }
            // Current read: an earlier repeatable-read snapshot cannot miss a supplement just accepted under these locks.
            abort_if(SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)
                ->where('kind', 'membership_review')->lockForUpdate()->first(), 409, 'The original membership supplement is already recorded.');
            abort_unless($this->program($row) === null && $row->revision === $expectedRevision, 409, 'Review the current membership and agreement revision.');

            return SubscriptionBillingAction::create(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id,
                'request_uuid' => $requestUuid, 'kind' => 'membership_review', 'actor_type' => 'user', 'actor_reference' => (string) $actor->id,
                'expected_revision' => $expectedRevision, 'request' => $request, 'before_state' => ['membership_program' => null],
                'after_state' => ['membership_program' => 'candle_club', 'review_hash' => $hash]]);
        });
    }

    private function candidate(SubscriptionBillingAgreement $row, User $actor, array $input, int $revision): array
    {
        app(SubscriptionReplacementAccess::class)->owner($row->tenant_id, $actor, $row->member_id, 'setup');
        app(SubscriptionReplacementAccess::class)->member($row);
        $data = Validator::make($input, [
            'membership_program' => ['required', 'in:candle_club'],
            'membership_reference' => ['required', 'string', 'min:3', 'max:190', 'regex:/\A[a-zA-Z0-9:_\-.]+\z/'],
            'source_membership_reviewed' => ['required', 'accepted'],
            'customer_gid' => ['required', 'string', 'regex:#\Agid://shopify/Customer/[1-9][0-9]*\z#'],
            'source_subscription_ids' => ['required', 'array', 'min:1', 'max:20'],
            'source_subscription_ids.*' => ['required', 'string', 'distinct', 'regex:/\A[1-9][0-9]*\z/'],
        ])->validate();
        $subject = $this->subject($row);
        sort($data['source_subscription_ids'], SORT_STRING);
        abort_unless($data['customer_gid'] === $subject['customer_gid'] && $data['source_subscription_ids'] === $subject['source_subscription_ids'],
            409, 'Review the exact original customer and every source subscription.');

        return $subject + ['membership_program' => 'candle_club', 'membership_reference' => $data['membership_reference'],
            'source_membership_reviewed' => true, 'reviewed_by' => $actor->id, 'expected_revision' => $revision];
    }

    private function subject(SubscriptionBillingAgreement $row): array
    {
        $original = SubscriptionBillingAuthorization::original($row);
        $ids = array_column($original['terms']['lines'], 'subscription_id');
        sort($ids, SORT_STRING);

        return ['version' => 1, 'tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'agreement_public_id' => $row->public_id,
            'member_id' => $row->member_id, 'original_terms_hash' => $original['terms_hash'],
            'customer_gid' => 'gid://shopify/Customer/'.$original['terms']['shopify_customer_id'], 'source_subscription_ids' => $ids];
    }
}

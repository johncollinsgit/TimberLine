<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\Tenant;
use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Str;

/** Request/review records only. This service cannot link or move any wallet. */
final class PosExistingWalletReview
{
    private const DISCLOSURE = 'I independently verified that this Shopify account holder controls the selected Everbranch wallet. Matching contact details or payment cards alone do not prove control.';

    public const CUSTOMER_DISCLOSURE = 'I request a review to connect my existing Everbranch rewards wallet to this Shopify account. A review does not connect the wallet.';

    public const CUSTOMER_DISCLOSURE_VERSION = 'existing-wallet-review-v1';

    public static function customerDisclosureHash(): string
    {
        return Contract::hash([self::CUSTOMER_DISCLOSURE_VERSION, self::CUSTOMER_DISCLOSURE]);
    }

    public static function reviewerDisclosureHash(): string
    {
        return Contract::hash(['existing-wallet-reviewer-v1', self::DISCLOSURE]);
    }

    public function __construct(private PosStore $store, private PosCustomerDirectory $customers) {}

    /** The caller must resolve the subject from a fresh authenticated Shopify session. */
    public function request(int $tenant, string $shopifySubject, string $requestId): array
    {
        $this->enabled();
        if ($tenant < 1 || ! Str::isUuid($requestId) || ! $this->subjectValid($shopifySubject)) {
            throw new PosException('wallet_review_invalid', 422);
        }
        $reference = substr(Contract::hash(['existing-wallet-review', $this->store->runtime(), $tenant, $shopifySubject, strtolower($requestId)]), 0, 32);

        return $this->store->db->transaction(function () use ($tenant, $shopifySubject, $reference): array {
            Tenant::query()->whereKey($tenant)->lockForUpdate()->firstOrFail();
            $existing = $this->record($tenant, $reference, 'wallet_link_requested');
            if (! $existing) {
                $existing = ['subject' => $shopifySubject, 'expires_at' => now()->addDay()->toIso8601String(),
                    'disclosure' => self::CUSTOMER_DISCLOSURE, 'disclosure_version' => self::CUSTOMER_DISCLOSURE_VERSION,
                    'disclosure_hash' => self::customerDisclosureHash()];
                $this->store->event($tenant, $reference, 'wallet_link_requested', $existing);
            }
            if (! hash_equals($existing['subject'] ?? '', $shopifySubject)) {
                throw new PosException('wallet_review_unavailable', 404);
            }

            return $this->customerStatus($tenant, $reference, $shopifySubject);
        }, 3);
    }

    /** Status reveals no profile or wallet information to the customer. */
    public function customerStatus(int $tenant, string $reference, string $shopifySubject): array
    {
        $this->enabled();
        $request = $this->requested($tenant, $reference);
        if (! $this->subjectValid($shopifySubject) || ! hash_equals($request['subject'], $shopifySubject)) {
            throw new PosException('wallet_review_unavailable', 404);
        }
        $expired = now()->greaterThanOrEqualTo($request['expires_at']);
        $reviewed = (bool) $this->record($tenant, $reference, 'wallet_link_reviewed');

        return ['reference' => $reference, 'state' => $expired ? 'expired' : ($reviewed ? 'review_recorded' : 'awaiting_review'),
            'linked' => false, 'finalization_available' => false, 'expires_at' => $request['expires_at']];
    }

    /** The UI may show this to the assigned tenant owner; the code is not an identity proof. */
    public function reviewDetails(array $scope, string $reference): array
    {
        $this->enabled();
        $this->admin($scope);
        $tenant = (int) $scope['tenant_id'];
        $request = $this->requested($tenant, $reference);
        $review = $this->record($tenant, $reference, 'wallet_link_reviewed');

        return ['reference' => $reference, 'shopify_subject' => $request['subject'], 'expires_at' => $request['expires_at'],
            'state' => now()->greaterThanOrEqualTo($request['expires_at']) ? 'expired' : ($review ? 'review_recorded' : 'awaiting_review'),
            'reviewed_profile_id' => $review['profile_id'] ?? null, 'disclosure' => self::DISCLOSURE, 'disclosure_hash' => self::reviewerDisclosureHash(),
            'linked' => false, 'finalization_available' => false];
    }

    public function review(array $scope, string $reference, int $profileId, string $evidence, bool $confirmed, string $disclosureHash): array
    {
        $this->enabled();
        $this->admin($scope);
        if (! $confirmed || ! hash_equals(self::reviewerDisclosureHash(), $disclosureHash)
            || ! in_array($evidence, ['owner_attests_existing_wallet_session', 'owner_verified_independent_evidence'], true)) {
            throw new PosException('wallet_review_confirmation_required', 422);
        }
        $tenant = (int) $scope['tenant_id'];

        return $this->store->db->transaction(function () use ($scope, $tenant, $reference, $profileId, $evidence): array {
            Tenant::query()->whereKey($tenant)->lockForUpdate()->firstOrFail();
            $request = $this->requested($tenant, $reference);
            if (now()->greaterThanOrEqualTo($request['expires_at'])) {
                throw new PosException('wallet_review_expired', 409);
            }
            $profile = $this->customers->profile($tenant, $profileId);
            if ((int) $profile->id !== $profileId) {
                throw new PosException('customer_selection_changed', 409);
            }
            $prior = $this->record($tenant, $reference, 'wallet_link_reviewed');
            if ($prior && ((int) $prior['profile_id'] !== $profileId || $prior['evidence'] !== $evidence)) {
                throw new PosException('wallet_review_conflict', 409);
            }
            if (! $prior) {
                $this->store->event($tenant, $reference, 'wallet_link_reviewed', ['subject' => $request['subject'],
                    'profile_id' => $profileId, 'evidence' => $evidence, 'disclosure' => self::DISCLOSURE, 'disclosure_hash' => self::reviewerDisclosureHash(),
                    'reviewed_by' => (int) $scope['actor_id'], 'register_id' => $scope['register']->id, 'reviewed_at' => now()->toIso8601String()]);
            }

            return $this->reviewDetails($scope, $reference);
        }, 3);
    }

    private function requested(int $tenant, string $reference): array
    {
        if ($tenant < 1 || ! preg_match('/^[a-f0-9]{32}$/D', $reference)
            || ! ($data = $this->record($tenant, $reference, 'wallet_link_requested'))
            || ! $this->subjectValid($data['subject'] ?? null) || ! is_string($data['expires_at'] ?? null)
            || strtotime($data['expires_at']) === false) {
            throw new PosException('wallet_review_unavailable', 404);
        }

        return $data;
    }

    private function record(int $tenant, string $reference, string $code): ?array
    {
        $row = $this->store->rows('events', $tenant)->where('sale_id', $reference)->where('code', $code)->first();

        return $row ? $this->store->decrypt($row->details) : null;
    }

    private function subjectValid(mixed $value): bool
    {
        return is_string($value) && preg_match('~^gid://shopify/Customer/[1-9][0-9]{0,19}$~D', $value) === 1;
    }

    private function admin(array $scope): void
    {
        if (! in_array($scope['role'] ?? null, ['owner', 'tenant_owner', 'admin'], true)
            || (int) ($scope['tenant_id'] ?? 0) < 1 || (int) ($scope['actor_id'] ?? 0) < 1 || ! isset($scope['register']->id)) {
            throw new PosException('organization_admin_required', 403);
        }
    }

    private function enabled(): void
    {
        if (config('pos_live.existing_wallet_review_enabled', false) !== true) {
            throw new PosException('wallet_review_disabled', 503);
        }
    }
}

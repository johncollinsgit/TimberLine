<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\MarketingProfile;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use Illuminate\Support\Str;

/**
 * Short-lived, one-use customer-present credential. Its holder may authorize
 * one eligible in-person purchase up to the disclosed cap; it does not attest
 * to a particular sale or amount. Issuers must authenticate the customer.
 */
final class PosRewardCustomerProof
{
    private const PREFIX = 'everbranch-reward:v1:';

    private const LIFETIME_SECONDS = 120;

    public const DISCLOSURE_VERSION = 'capped_scan_v1';

    public function __construct(private PosStore $store, private CanonicalMarketingProfileResolver $identities) {}

    /** @return array{qr_payload:string,expires_at:string,max_minor:int,policy_version:string,disclosure_version:string} */
    public function issueForAuthenticatedProfile(MarketingProfile $profile, array $accepted, int $accountLinkId): array
    {
        if (config('pos_live.in_person_rewards_enabled', false) !== true || (int) $profile->tenant_id < 1) {
            throw new PosException('in_person_rewards_disabled', 403);
        }
        $canonical = $this->identities->byId((int) $profile->tenant_id, (int) $profile->id);
        if (! $profile->exists || ! $canonical || (int) $canonical->id !== (int) $profile->id) {
            throw new PosException('customer_identity_review_required', 409);
        }
        if (! app(PosVerifiedCustomerAccount::class)->verified($profile, $accountLinkId)) {
            throw new PosException('customer_identity_review_required', 409);
        }
        $policy = app(PosRewardRedemptionSettings::class)->tenant((int) $profile->tenant_id);
        if (($accepted['max_minor'] ?? null) !== $policy['max_minor']
            || ! is_string($accepted['policy_version'] ?? null) || ! hash_equals($policy['version'], $accepted['policy_version'])
            || ($accepted['disclosure_version'] ?? null) !== self::DISCLOSURE_VERSION) {
            throw new PosException('reward_disclosure_changed', 409);
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = now()->addSeconds(self::LIFETIME_SECONDS);
        $this->store->db->table('pos_reward_authorizations')->insert([
            'id' => (string) Str::uuid(), 'runtime_id' => $this->store->runtime(), 'tenant_id' => (int) $profile->tenant_id,
            'marketing_profile_id' => (int) $profile->id, 'token_hash' => hash('sha256', $token),
            'cap_minor' => $policy['max_minor'], 'policy_version' => $policy['version'], 'disclosure_version' => self::DISCLOSURE_VERSION,
            'account_link_id' => $accountLinkId,
            'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['qr_payload' => self::PREFIX.$token, 'expires_at' => $expires->toIso8601String(),
            'max_minor' => $policy['max_minor'], 'policy_version' => $policy['version'], 'disclosure_version' => self::DISCLOSURE_VERSION];
    }

    /** Resolve before quote calculation without granting or binding funds. */
    public function preview(int $tenant, string $payload): object
    {
        $row = $this->rows($tenant, $payload)->first();
        if (! $row || ! $this->hasTerms($row) || $row->sale_id !== null || now()->greaterThanOrEqualTo($row->expires_at)) {
            throw new PosException('reward_authorization_invalid', 403);
        }

        return $row;
    }

    /** Called only within the original sale's locked transaction. */
    public function bind(int $tenant, string $payload, string $saleId): object
    {
        if ($this->store->db->transactionLevel() < 1 || ! preg_match('/^[a-f0-9]{32}$/D', $saleId)) {
            throw new PosException('reward_authorization_invalid', 403);
        }
        $row = $this->rows($tenant, $payload)->lockForUpdate()->first();
        if (! $row || ! $this->hasTerms($row) || ($row->sale_id !== null && $row->sale_id !== $saleId)
            || ($row->sale_id === null && now()->greaterThanOrEqualTo($row->expires_at))) {
            throw new PosException('reward_authorization_invalid', 403);
        }
        if ($row->sale_id === null) {
            $this->store->db->table('pos_reward_authorizations')->where('id', $row->id)->update([
                'sale_id' => $saleId, 'bound_at' => now(), 'updated_at' => now(),
            ]);
            $row->sale_id = $saleId;
        }

        return $row;
    }

    private function hasTerms(object $row): bool
    {
        return (int) ($row->cap_minor ?? 0) > 0
            && (int) ($row->account_link_id ?? 0) > 0
            && is_string($row->policy_version ?? null) && preg_match('/^[a-f0-9]{64}$/D', $row->policy_version) === 1
            && ($row->disclosure_version ?? null) === self::DISCLOSURE_VERSION;
    }

    private function rows(int $tenant, string $payload): \Illuminate\Database\Query\Builder
    {
        if ($tenant < 1 || ! preg_match('/^everbranch-reward:v1:([A-Za-z0-9_-]{43})$/D', $payload, $matches)) {
            throw new PosException('reward_authorization_invalid', 403);
        }

        return $this->store->db->table('pos_reward_authorizations')->where('runtime_id', $this->store->runtime())
            ->where('tenant_id', $tenant)->where('token_hash', hash('sha256', $matches[1]));
    }
}

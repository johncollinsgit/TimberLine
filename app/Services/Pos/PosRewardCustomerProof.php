<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\MarketingProfile;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use Illuminate\Support\Str;

/**
 * Short-lived customer-present proof. The issuer MUST resolve the profile from
 * an authenticated customer session, not a cashier-provided identifier.
 * No endpoint is registered until that binding and policy checks are complete.
 */
final class PosRewardCustomerProof
{
    private const PREFIX = 'everbranch-reward:v1:';

    private const LIFETIME_SECONDS = 120;

    public function __construct(private PosStore $store, private CanonicalMarketingProfileResolver $identities) {}

    /** @return array{qr_payload:string,expires_at:string} */
    public function issueForAuthenticatedProfile(MarketingProfile $profile): array
    {
        if (config('pos_live.in_person_rewards_enabled', false) !== true || (int) $profile->tenant_id < 1) {
            throw new PosException('in_person_rewards_disabled', 403);
        }
        $canonical = $this->identities->byId((int) $profile->tenant_id, (int) $profile->id);
        if (! $profile->exists || ! $canonical || (int) $canonical->id !== (int) $profile->id) {
            throw new PosException('customer_identity_review_required', 409);
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = now()->addSeconds(self::LIFETIME_SECONDS);
        $this->store->db->table('pos_reward_authorizations')->insert([
            'id' => (string) Str::uuid(), 'runtime_id' => $this->store->runtime(), 'tenant_id' => (int) $profile->tenant_id,
            'marketing_profile_id' => (int) $profile->id, 'token_hash' => hash('sha256', $token),
            'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['qr_payload' => self::PREFIX.$token, 'expires_at' => $expires->toIso8601String()];
    }

    /** Resolve before quote calculation without granting or binding funds. */
    public function preview(int $tenant, string $payload): object
    {
        $row = $this->rows($tenant, $payload)->first();
        if (! $row || $row->sale_id !== null || now()->greaterThanOrEqualTo($row->expires_at)) {
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
        if (! $row || ($row->sale_id !== null && $row->sale_id !== $saleId)
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

    private function rows(int $tenant, string $payload): \Illuminate\Database\Query\Builder
    {
        if ($tenant < 1 || ! preg_match('/^everbranch-reward:v1:([A-Za-z0-9_-]{43})$/D', $payload, $matches)) {
            throw new PosException('reward_authorization_invalid', 403);
        }

        return $this->store->db->table('pos_reward_authorizations')->where('runtime_id', $this->store->runtime())
            ->where('tenant_id', $tenant)->where('token_hash', hash('sha256', $matches[1]));
    }
}
